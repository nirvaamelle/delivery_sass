<?php

namespace App\Domain\Hris;

use App\Domain\Support\Money;
use App\Models\PayrollLineDay;
use App\Models\PayrollRun;
use App\Models\PayrollVarianceExplanation;
use App\Models\Project;
use App\Models\User;
use DomainException;

/**
 * F10 — the payroll register variance, per project, against the last cutoff.
 *
 * **Per project, because a run total hides the movement that matters.** Move
 * ₱1,200 of labour from project A to project B and the run total does not change
 * by a centavo; a total-only review approves it, while A's cost per unit is now
 * overstated and B's understated, and both numbers go into the ledger.
 *
 * **Against the last cutoff that was actually computed.** Draft and cancelled
 * runs are skipped rather than compared against, so the comparison is always to
 * a real register. Skipping a cutoff does not escape the gate — the next run is
 * simply compared to the one before the gap. An organization's FIRST run has
 * nothing to vary against and sets the baseline.
 *
 * Labour totals are summed in bcmath from the decrypted day amounts, never in
 * SQL — the amounts are ciphertext at rest (P3-08), and SUM() over ciphertext is
 * a number that means nothing and looks like one that does.
 *
 * PLACEHOLDER: B3 — `payroll.variance.tolerance_percent` defaults to zero, which
 * is the deck's literal rule: every variance is explained.
 */
class PayrollVarianceService
{
    /**
     * The most recent computed run before this one, if any.
     */
    public function previousRunFor(PayrollRun $run): ?PayrollRun
    {
        return PayrollRun::query()
            ->where('organization_id', $run->organization_id)
            ->whereKeyNot($run->getKey())
            ->whereDate('period_end', '<', $run->period_start)
            ->whereIn('status', [PayrollRunStatus::Computed, PayrollRunStatus::Approved, PayrollRunStatus::Released])
            ->orderByDesc('period_end')
            ->first();
    }

    /**
     * Labour cost on each project in a run.
     *
     * @return array<int, string> project id => amount
     */
    public function laborByProject(PayrollRun $run): array
    {
        $totals = [];

        $days = PayrollLineDay::query()
            ->whereIn('payroll_line_id', $run->lines()->pluck('id'))
            ->get();

        foreach ($days as $day) {
            $projectId = (int) $day->project_id;
            $totals[$projectId] = Money::sum($totals[$projectId] ?? '0.0000', (string) $day->amount);
        }

        ksort($totals);

        return $totals;
    }

    /**
     * Each project's movement against the last cutoff.
     *
     * Includes projects that appear only on one side: a project new to the
     * register is a variance from zero, and one that dropped off is a variance to
     * zero. Both are worth a sentence.
     *
     * @return array<int, array{project_id: int, previous: string, current: string, variance: string, requires_explanation: bool}>
     */
    public function variancesFor(PayrollRun $run): array
    {
        $previousRun = $this->previousRunFor($run);

        if ($previousRun === null) {
            return [];
        }

        $current = $this->laborByProject($run);
        $previous = $this->laborByProject($previousRun);
        $tolerance = (string) config('payroll.variance.tolerance_percent', '0.00');

        $projectIds = array_unique(array_merge(array_keys($current), array_keys($previous)));
        sort($projectIds);

        $rows = [];

        foreach ($projectIds as $projectId) {
            $now = $current[$projectId] ?? '0.0000';
            $before = $previous[$projectId] ?? '0.0000';
            $variance = bcsub($now, $before, Money::SCALE);

            // The allowance is a share of the PREVIOUS figure, so a project new to
            // the register — previous zero — has no allowance at all.
            $allowed = Money::round(bcdiv(bcmul($before, $tolerance, Money::WORKING_SCALE), '100', Money::WORKING_SCALE));
            $magnitude = ltrim($variance, '-');

            $rows[] = [
                'project_id' => (int) $projectId,
                'previous' => $before,
                'current' => $now,
                'variance' => $variance,
                // Strictly OVER the allowance: a variance sitting exactly on the
                // tolerance is within it.
                'requires_explanation' => bccomp($magnitude, $allowed, Money::SCALE) > 0,
            ];
        }

        return $rows;
    }

    /**
     * Projects whose variance needs an explanation and has none.
     *
     * @return array<int, int>
     */
    public function unexplained(PayrollRun $run): array
    {
        $explained = PayrollVarianceExplanation::query()
            ->where('payroll_run_id', $run->getKey())
            ->pluck('project_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $open = array_filter(
            $this->variancesFor($run),
            fn (array $row): bool => $row['requires_explanation'] && ! in_array($row['project_id'], $explained, true),
        );

        return array_values(array_map(fn (array $row): int => $row['project_id'], $open));
    }

    /**
     * Record why one project's labour cost moved.
     *
     * @throws DomainException when the register is not under review, the
     *                         explanation is blank, or the project has no
     *                         variance that needs one
     */
    public function explain(PayrollRun $run, Project $project, string $explanation, User $by): PayrollVarianceExplanation
    {
        if ($run->status !== PayrollRunStatus::Computed) {
            // Explanations belong to the review. Written after approval they
            // justify a decision rather than inform it.
            throw new DomainException(sprintf(
                'Payroll run %s is %s. Variance explanations are written during review, before the register is approved.',
                $run->number,
                $run->status->value,
            ));
        }

        if (trim($explanation) === '') {
            throw new DomainException('F10 requires the variance EXPLAINED. A blank explanation is an acknowledgement.');
        }

        $row = collect($this->variancesFor($run))->firstWhere('project_id', (int) $project->getKey());

        if ($row === null || ! $row['requires_explanation']) {
            // An explanation with nothing to explain is noise, and allowing it
            // would let "explained" be satisfied by pasting a sentence everywhere.
            throw new DomainException(sprintf(
                'Project %s has no variance against the last cutoff that needs explaining.',
                $project->code,
            ));
        }

        return PayrollVarianceExplanation::query()->create([
            'payroll_run_id' => $run->getKey(),
            'project_id' => $project->getKey(),
            'previous_amount' => $row['previous'],
            'current_amount' => $row['current'],
            'variance' => $row['variance'],
            'explanation' => $explanation,
            'explained_at' => now(),
            'explained_by_user_id' => $by->getKey(),
        ]);
    }
}
