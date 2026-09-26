<?php

namespace App\Domain\Posting;

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Hris\PayrollRunStatus;
use App\Domain\Support\Money;
use App\Models\CostCode;
use App\Models\PayrollLine;
use App\Models\PayrollLineDay;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Labour cost reaching `project_cost_ledger` — P3-11, and the last clause of
 * Phase 3's exit gate.
 *
 * Three decisions, each of which the obvious implementation gets wrong.
 *
 * **Per PROJECT, not per employee.** A ledger row per person would put every
 * salary into the P&L, readable by anybody who can read the ledger — and the
 * whole of Phase 3 has been careful to encrypt exactly that. The ledger is a
 * cost record, not a payroll register: it needs cost by project, which is what
 * `payroll_line_days` already carries. Two people on one site is one row, and
 * neither person's pay is recoverable from it.
 *
 * **At GROSS, not net.** Labour cost to the project is what the work cost the
 * company, not what the employee took home. Posting net would understate every
 * project by the statutory deductions and leave those deductions accounted
 * nowhere at all.
 *
 * **On the PAYROLL calendar.** F3's whole point: payroll closes semi-monthly,
 * billing monthly, OPEX on day 26, and "nothing books after cutoff" means
 * nothing until you say which cutoff.
 *
 * Like the material and revenue posters, nothing is written directly here —
 * `LedgerPoster` is the only code permitted to write the ledger, so the cutoff
 * check, the cross-organization check and the append-only guarantee all still
 * apply without being restated.
 */
class LaborCostPoster
{
    public function __construct(
        private readonly LedgerPoster $ledger,
    ) {}

    /**
     * Post one register's labour cost, one row per project.
     *
     * @return array<int, ProjectCostLedgerEntry>
     *
     * @throws DomainException when the register is unapproved, already posted, or
     *                         a project has no cost code to post against
     */
    public function post(PayrollRun $run, ?CostCode $costCode = null, ?string $description = null): array
    {
        if (! in_array($run->status, [PayrollRunStatus::Approved, PayrollRunStatus::Released], true)) {
            // A computed register can still be changed by the variance review,
            // and the ledger is append-only: a posting made from one would need
            // a reversing entry rather than an edit.
            throw new DomainException(sprintf(
                'Payroll run %s is %s. Labour cost posts from an approved register — a computed one can still change.',
                $run->number,
                $run->status->value,
            ));
        }

        if ($run->posted_at !== null) {
            throw new DomainException(sprintf(
                'Payroll run %s was posted to the ledger on %s. A second posting doubles labour cost on every project in the run.',
                $run->number,
                $run->posted_at->toDateTimeString(),
            ));
        }

        $byProject = $this->grossByProject($run);

        if ($byProject === []) {
            throw new DomainException(sprintf('Payroll run %s has no priced days to post.', $run->number));
        }

        return DB::transaction(function () use ($run, $byProject, $costCode, $description): array {
            $entries = [];

            foreach ($byProject as $projectId => $amount) {
                $project = Project::query()->findOrFail($projectId);

                $entries[] = $this->ledger->post(
                    project: $project,
                    costCode: $this->costCodeFor($project, $costCode),
                    category: LedgerCategory::Labor,
                    // Gross: what the work cost the company.
                    amount: $amount,
                    sourceDocument: $run,
                    documentNumber: $run->number,
                    // F3 — the payroll calendar, not the billing one.
                    cutoffType: CutoffType::Payroll,
                    documentDate: $run->period_end,
                    description: $description ?? sprintf('Labour: %s to %s', $run->period_start->toDateString(), $run->period_end->toDateString()),
                );
            }

            // Stamped inside the same transaction, so a refused posting cannot
            // leave a register claiming to be in a ledger that never took it.
            $run->update(['posted_at' => now()]);

            return $entries;
        });
    }

    /**
     * Labour per project: the priced days, plus each line's leave pay and
     * allowances spread across the projects that line worked on.
     *
     * bcmath rather than SQL SUM(): the amounts are ciphertext at rest, and a
     * SUM over ciphertext is a number that means nothing while looking like one
     * that does.
     *
     * **Why benefits are spread, not left out.** Leave pay and allowances are
     * labour cost but belong to no day, and so to no project. Left out, the
     * ledger would stop matching the register. They are shared in proportion to
     * the pay each project earned on that line, with the rounding remainder on
     * the largest share, so the posted total is exactly gross plus non-taxable
     * allowances.
     *
     * PLACEHOLDER: proportional attribution is the build's choice
     * (DECISIONS-PENDING.md, benefits, unnumbered).
     *
     * @return array<int, string>
     */
    public function grossByProject(PayrollRun $run): array
    {
        $totals = [];

        foreach ($run->lines()->get() as $line) {
            $earned = [];

            foreach (PayrollLineDay::query()->where('payroll_line_id', $line->getKey())->get() as $day) {
                $projectId = (int) $day->project_id;
                $earned[$projectId] = Money::sum($earned[$projectId] ?? '0.0000', (string) $day->amount);
            }

            foreach ($this->withBenefitsSpread($line, $earned) as $projectId => $amount) {
                $totals[$projectId] = Money::sum($totals[$projectId] ?? '0.0000', $amount);
            }
        }

        ksort($totals);

        return $totals;
    }

    /**
     * @param  array<int, string>  $earned  pay earned per project on one line
     * @return array<int, string>
     */
    private function withBenefitsSpread(PayrollLine $line, array $earned): array
    {
        $extras = Money::sum(
            (string) ($line->leave_pay ?? '0'),
            (string) ($line->taxable_allowances ?? '0'),
            (string) ($line->non_taxable_allowances ?? '0'),
        );

        $base = Money::sum('0', ...array_values($earned));

        if (Money::isZero($extras) || Money::isZero($base)) {
            return $earned;
        }

        // The largest share absorbs the remainder; ties go to the lowest id.
        ksort($earned);
        $largest = (int) array_key_first($earned);

        foreach ($earned as $projectId => $amount) {
            if (bccomp($amount, $earned[$largest], Money::SCALE) > 0) {
                $largest = $projectId;
            }
        }

        $allocated = '0.0000';
        $spread = $earned;

        foreach ($earned as $projectId => $amount) {
            if ($projectId === $largest) {
                continue;
            }

            $share = Money::round(bcdiv(bcmul($extras, $amount, Money::WORKING_SCALE), $base, Money::WORKING_SCALE));
            $spread[$projectId] = Money::sum($amount, $share);
            $allocated = Money::sum($allocated, $share);
        }

        $spread[$largest] = Money::sum($earned[$largest], bcsub($extras, $allocated, Money::SCALE));

        return $spread;
    }

    /**
     * Which cost code the labour posts against.
     *
     * PLACEHOLDER: a DTR records WHERE somebody worked, not which part of the
     * works their hours belong to, so labour takes the project's first cost code
     * unless the caller names one. Mapping labour to a WBS code is a
     * chart-of-accounts question the client has not answered.
     *
     * @throws DomainException when the project has no cost code at all
     */
    private function costCodeFor(Project $project, ?CostCode $costCode): CostCode
    {
        if ($costCode !== null) {
            return $costCode;
        }

        $resolved = CostCode::query()
            ->where('organization_id', $project->organization_id)
            ->orderBy('code')
            ->first();

        if ($resolved === null) {
            throw new DomainException(sprintf(
                'Project %s has no cost code to post labour against, and a ledger row without one cannot be traced.',
                $project->code,
            ));
        }

        return $resolved;
    }
}
