<?php

namespace App\Domain\Billing;

use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\Accomplishment;
use App\Models\Billing;
use App\Models\BillingDeductionBlock;
use App\Models\BillingMilestone;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Progress billing, and slide 6's approved-or-returned branch.
 *
 * **The returned branch is a first-class path, not an error case.** A returned
 * billing keeps its number — it is the same document coming back, and a fresh
 * number would make two documents out of one conversation with the client. It
 * gains a reason, which is what the QS re-measures against.
 *
 * **And its deducted lines stay blocked from the next submission.** That clause
 * is the whole task. Billing the same item twice is not a hypothetical failure:
 * it is the default one. The QS reworks the submission from the spreadsheet it
 * was copied from, the deducted line is still in it, and without a block the
 * client's evaluator is the only control standing in the way. So a deduction
 * outlives the billing it came from, lives at project level where the next
 * submission will meet it, and is cleared only by a named person recording a
 * re-measurement.
 *
 * Three gates run before a submission is accepted, all from slide 6:
 *
 *   - the milestone's document set must be complete — F4;
 *   - the verified accomplishment must actually reach the milestone, which is
 *     "no billing without a verified statement of accomplishment" with the part
 *     people miss: complete paperwork on 42% does not open a 50% billing;
 *   - no line on the submission may be one the client already deducted.
 *
 * Retention is withheld on every billing. The RATE is copied onto the billing
 * from the contract rather than read back through the relation: a rate
 * renegotiated next quarter must not restate what was withheld last month.
 */
class BillingService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly DocumentLinker $links,
        private readonly BillingScheduleService $schedules,
        private readonly AccomplishmentService $accomplishments,
    ) {}

    /**
     * Submit a billing against a milestone.
     *
     * @param  array<int, array{line_key: string, description: string, amount: string, cost_code_id?: int}>  $lines
     *
     * @throws MissingMilestoneDocumentsException when the document set is incomplete
     * @throws InsufficientAccomplishmentException when the works have not reached the milestone
     * @throws DeductedLineException when a line is one the client already deducted
     */
    public function submit(
        BillingMilestone $milestone,
        array $lines,
        ?User $by = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
    ): Billing {
        if ($lines === []) {
            throw new DomainException('A billing with no lines bills nothing and cannot be evaluated.');
        }

        $contract = $milestone->schedule()->sole()->contract()->sole();
        $project = $contract->project()->sole();

        // F4 first: it is the cheapest check and the one whose failure is most
        // actionable — a list of documents to go and find.
        $this->schedules->assertSubmissionOpen($milestone);

        $accomplishment = $this->assertAccomplishmentReaches($milestone, $project);
        $this->assertNoDeductedLines($project, $lines);

        $gross = '0.0000';

        foreach ($lines as $line) {
            $gross = Money::sum($gross, $line['amount']);
        }

        $retentionRate = (string) ($contract->retention_rate ?? config('billing.retention_rate', '10.00'));
        $retention = Money::round(bcdiv(bcmul($gross, $retentionRate, Money::WORKING_SCALE), '100', Money::WORKING_SCALE));
        $net = bcsub($gross, $retention, Money::SCALE);

        return DB::transaction(function () use ($project, $contract, $milestone, $accomplishment, $lines, $gross, $retentionRate, $retention, $net, $by, $periodStart, $periodEnd): Billing {
            $billing = Billing::query()->create([
                'project_id' => $project->getKey(),
                'contract_id' => $contract->getKey(),
                'billing_milestone_id' => $milestone->getKey(),
                'accomplishment_id' => $accomplishment?->getKey(),
                'number' => $this->numbering->next('BILL'),
                'status' => BillingStatus::Submitted,
                'period_start' => $periodStart ?? now()->startOfMonth(),
                'period_end' => $periodEnd ?? now()->endOfMonth(),
                'gross_amount' => $gross,
                'retention_rate' => $retentionRate,
                'retention_amount' => $retention,
                'net_amount' => $net,
                'submitted_at' => now(),
                'submitted_by_user_id' => $by?->getKey(),
            ]);

            foreach ($lines as $line) {
                $billing->lines()->create([
                    'line_key' => $line['line_key'],
                    'description' => $line['description'],
                    'amount' => $line['amount'],
                    'cost_code_id' => $line['cost_code_id'] ?? null,
                ]);
            }

            if ($accomplishment !== null) {
                $this->links->link($accomplishment, $billing);
            }

            return $billing->refresh();
        });
    }

    /**
     * The client approves. This is what opens the invoice.
     *
     * @throws DomainException when the billing is not awaiting evaluation
     */
    public function approve(Billing $billing, CarbonInterface $evaluatedAt, ?string $remarks = null): Billing
    {
        $this->assertAwaitingEvaluation($billing);

        $billing->update([
            'status' => BillingStatus::Approved,
            'evaluated_at' => $evaluatedAt,
            'remarks' => $remarks,
        ]);

        return $billing->refresh();
    }

    /**
     * The client sends it back — slide 6's other branch.
     *
     * @param  array<int, array{line_key: string, reason: string}>  $deductions
     *
     * @throws DomainException when no reason is given, or a deduction names a
     *                         line that is not on the billing
     */
    public function returnForRemeasurement(
        Billing $billing,
        CarbonInterface $evaluatedAt,
        string $reason,
        array $deductions = [],
    ): Billing {
        $this->assertAwaitingEvaluation($billing);

        if (trim($reason) === '') {
            throw new DomainException(
                'A returned billing needs a reason. It is what tells the QS what to re-measure, and without it the returned branch is just a rejection.'
            );
        }

        return DB::transaction(function () use ($billing, $evaluatedAt, $reason, $deductions): Billing {
            foreach ($deductions as $deduction) {
                $line = $billing->lines()->where('line_key', $deduction['line_key'])->first();

                if ($line === null) {
                    throw new DomainException(sprintf(
                        'Billing %s has no line "%s" to deduct.',
                        $billing->number,
                        $deduction['line_key'],
                    ));
                }

                if (trim($deduction['reason']) === '') {
                    throw new DomainException(sprintf(
                        'The deduction of "%s" carries no reason. Slide 6 asks for the deduction AND its reason, because the reason is what gets re-measured.',
                        $deduction['line_key'],
                    ));
                }

                $line->update([
                    'deducted' => true,
                    'deduction_reason' => $deduction['reason'],
                ]);

                // The block outlives this billing. That is the point.
                BillingDeductionBlock::query()->create([
                    'project_id' => $billing->project_id,
                    'line_key' => $deduction['line_key'],
                    'billing_id' => $billing->getKey(),
                    'reason' => $deduction['reason'],
                    'blocked_at' => now(),
                ]);
            }

            $billing->update([
                'status' => BillingStatus::Returned,
                'evaluated_at' => $evaluatedAt,
                'returned_reason' => $reason,
            ]);

            // The number is deliberately untouched: a returned billing is the
            // same document coming back, not a new one.
            return $billing->refresh();
        });
    }

    /**
     * Release a blocked line after it has actually been re-measured.
     *
     * @throws DomainException when nothing is blocked, or no note is given
     */
    public function clearDeduction(Project $project, string $lineKey, string $note, ?User $by = null): BillingDeductionBlock
    {
        if (trim($note) === '') {
            throw new DomainException(
                'Clearing a deduction needs a re-measurement note. Releasing a block is an act with a name on it, not a side effect of trying again.'
            );
        }

        $block = $this->activeBlock($project, $lineKey);

        if ($block === null) {
            throw new DomainException(sprintf(
                'Nothing is blocking "%s" on this project.',
                $lineKey,
            ));
        }

        $block->update([
            'cleared_at' => now(),
            'cleared_by_user_id' => $by?->getKey(),
            'clearance_note' => $note,
        ]);

        return $block->refresh();
    }

    /**
     * Line keys this project may not bill until they are re-measured.
     *
     * @return array<int, string>
     */
    public function blockedLineKeys(Project $project): array
    {
        return BillingDeductionBlock::query()
            ->where('project_id', $project->getKey())
            ->whereNull('cleared_at')
            ->pluck('line_key')
            ->unique()
            ->values()
            ->all();
    }

    private function activeBlock(Project $project, string $lineKey): ?BillingDeductionBlock
    {
        return BillingDeductionBlock::query()
            ->where('project_id', $project->getKey())
            ->where('line_key', $lineKey)
            ->whereNull('cleared_at')
            ->orderByDesc('blocked_at')
            ->first();
    }

    /**
     * @param  array<int, array{line_key: string}>  $lines
     *
     * @throws DeductedLineException
     */
    private function assertNoDeductedLines(Project $project, array $lines): void
    {
        $blocked = $this->blockedLineKeys($project);

        $offending = array_values(array_intersect(
            array_column($lines, 'line_key'),
            $blocked,
        ));

        if ($offending !== []) {
            // Every offending line, not just the first: the QS needs the whole
            // list to rework the submission once rather than four times.
            throw new DeductedLineException(sprintf(
                'These lines were deducted from an earlier billing and have not been re-measured: %s. Billing them again is billing the same item twice.',
                implode(', ', $offending),
            ));
        }
    }

    /**
     * @throws InsufficientAccomplishmentException
     */
    private function assertAccomplishmentReaches(BillingMilestone $milestone, Project $project): ?Accomplishment
    {
        $threshold = $milestone->accomplishment_threshold;

        if ($threshold === null) {
            // A downpayment bills on a signed contract, not on progress. There
            // is nothing to verify, and requiring a survey would make the
            // downpayment unbillable on day one.
            return null;
        }

        $verified = $this->accomplishments->verifiedPercentageFor($project);

        if (bccomp($verified, (string) $threshold, 2) < 0) {
            throw new InsufficientAccomplishmentException(sprintf(
                'Milestone %s bills at %s%% but the client has verified %s%%. Complete paperwork on work that has not been done is still work that has not been done.',
                $milestone->name,
                $threshold,
                $verified,
            ));
        }

        return $this->accomplishments->latestFor($project);
    }

    /**
     * @throws DomainException
     */
    private function assertAwaitingEvaluation(Billing $billing): void
    {
        if ($billing->status !== BillingStatus::Submitted) {
            throw new DomainException(sprintf(
                'Billing %s is %s, not awaiting evaluation. Evaluating it twice would raise two invoices against one billing.',
                $billing->number,
                $billing->status->value,
            ));
        }
    }
}
