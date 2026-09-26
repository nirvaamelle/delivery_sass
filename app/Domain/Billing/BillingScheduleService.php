<?php

namespace App\Domain\Billing;

use App\Domain\Support\Money;
use App\Models\BillingMilestone;
use App\Models\BillingSchedule;
use App\Models\Contract;
use App\Models\MilestoneDocument;
use App\Models\MilestoneDocumentRequirement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The billing schedule and its per-milestone document sets — F4.
 *
 * The finding is not that the deck omits the documents: slide 6 lists them per
 * milestone, precisely. It is that nothing in the system knows what they are,
 * which leaves "no billing without a verified statement of accomplishment" as
 * the only gate actually enforced and the other four milestones on the honour
 * system.
 *
 * Two decisions shape everything here.
 *
 * **The requirements are COPIED onto the milestone when the schedule is built**,
 * not read from config at check time. The requirement in force when a contract
 * was signed is the one that contract is held to, and editing the config to add
 * a document for next year's projects must not silently restate the rules of a
 * contract already halfway billed.
 *
 * **The percentages must total one hundred.** A schedule that does not is a
 * contract under- or over-billed by construction, and it is not discovered until
 * the final billing fails to balance — by which time the work is done. The
 * milestone amounts are then computed from the contract sum rather than stored,
 * so a variation order does not leave five stale figures behind it, and the last
 * milestone carries the rounding difference so the five amounts total the
 * contract exactly.
 */
class BillingScheduleService
{
    /**
     * Build the schedule for a contract from its type's template.
     *
     * @throws DomainException when the type is unknown or its percentages do
     *                         not total 100
     */
    public function createFor(Contract $contract, string $contractType = 'default'): BillingSchedule
    {
        $template = config("billing.contract_types.{$contractType}");

        if (! is_array($template) || $template === []) {
            // Falling back to the default would build slide 6's five milestones
            // for a contract that bills on some other schedule entirely — and it
            // would look entirely correct.
            throw new DomainException(sprintf(
                'No billing milestones are configured for contract type "%s". Configured types: %s.',
                $contractType,
                implode(', ', array_keys(config('billing.contract_types', []))),
            ));
        }

        $this->assertTotalsOneHundred($template, $contractType);

        return DB::transaction(function () use ($contract, $contractType, $template): BillingSchedule {
            $schedule = BillingSchedule::query()->create([
                'contract_id' => $contract->getKey(),
                'contract_type' => $contractType,
            ]);

            foreach (array_values($template) as $index => $definition) {
                $milestone = $schedule->milestones()->create([
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'percentage' => $definition['percentage'],
                    'trigger' => $definition['trigger'],
                    'accomplishment_threshold' => $definition['accomplishment_threshold'] ?? null,
                    'sequence' => $index + 1,
                ]);

                foreach ($definition['documents'] ?? [] as $document) {
                    $milestone->requirements()->create([
                        'document_key' => $document['key'],
                        'label' => $document['label'],
                        'required' => $document['required'] ?? true,
                    ]);
                }
            }

            return $schedule->refresh();
        });
    }

    /**
     * Put a document on file against a milestone.
     *
     * @throws DomainException when the milestone never asked for that document
     */
    public function attachDocument(
        BillingMilestone $milestone,
        string $documentKey,
        string $reference,
        ?User $by = null,
        ?string $remarks = null,
    ): MilestoneDocument {
        $expected = $milestone->requirements()->where('document_key', $documentKey)->exists();

        if (! $expected) {
            // Otherwise the requirement is satisfiable by attaching anything at
            // all: the count passes and the evidence is still absent.
            throw new DomainException(sprintf(
                'Milestone %s does not ask for "%s". It asks for: %s.',
                $milestone->code,
                $documentKey,
                implode(', ', $milestone->requirements()->pluck('document_key')->all()),
            ));
        }

        return MilestoneDocument::query()->updateOrCreate(
            [
                'billing_milestone_id' => $milestone->getKey(),
                'document_key' => $documentKey,
            ],
            [
                'reference' => $reference,
                'submitted_at' => now(),
                'submitted_by_user_id' => $by?->getKey(),
                'remarks' => $remarks,
            ],
        );
    }

    /**
     * Which required documents are still missing.
     *
     * Every one of them, not just the first: a QS who clears one blocker only to
     * be shown the next, one round trip at a time, is how a monthly cutoff gets
     * missed.
     *
     * @return array<int, string>
     */
    public function missingFor(BillingMilestone $milestone): array
    {
        $onFile = $milestone->documents()->pluck('document_key')->all();

        return $milestone->requirements()
            ->where('required', true)
            ->get()
            ->reject(fn (MilestoneDocumentRequirement $requirement): bool => in_array($requirement->document_key, $onFile, true))
            ->map(fn (MilestoneDocumentRequirement $requirement): string => $requirement->label)
            ->values()
            ->all();
    }

    public function isSubmissionOpen(BillingMilestone $milestone): bool
    {
        return $this->missingFor($milestone) === [];
    }

    /**
     * F4's gate, as a refusal.
     *
     * @throws MissingMilestoneDocumentsException when anything required is absent
     */
    public function assertSubmissionOpen(BillingMilestone $milestone): void
    {
        $missing = $this->missingFor($milestone);

        if ($missing !== []) {
            throw new MissingMilestoneDocumentsException(sprintf(
                'Milestone %s cannot be submitted. Missing: %s.',
                $milestone->name,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * What this milestone bills, against the contract sum.
     *
     * The final milestone carries the rounding difference, so the five amounts
     * total the contract exactly rather than totalling the sum of five
     * independent roundings — the same rule as the depreciation schedule in
     * P1-13, and for the same reason.
     */
    public function amountFor(BillingMilestone $milestone): string
    {
        $schedule = $milestone->schedule()->sole();
        $contractSum = (string) $schedule->contract()->sole()->contract_sum;

        $isLast = $milestone->sequence === (int) $schedule->milestones()->max('sequence');

        if (! $isLast) {
            return $this->share($contractSum, (string) $milestone->percentage);
        }

        $others = '0.0000';

        foreach ($schedule->milestones()->where('sequence', '<', $milestone->sequence)->get() as $earlier) {
            $others = Money::sum($others, $this->share($contractSum, (string) $earlier->percentage));
        }

        return bcsub($contractSum, $others, Money::SCALE);
    }

    /**
     * One percentage of the contract sum.
     */
    private function share(string $contractSum, string $percentage): string
    {
        return Money::round(
            bcdiv(bcmul($contractSum, $percentage, Money::WORKING_SCALE), '100', Money::WORKING_SCALE)
        );
    }

    /**
     * @param  array<int, array{percentage: string}>  $template
     *
     * @throws DomainException when the milestones do not total 100%
     */
    private function assertTotalsOneHundred(array $template, string $contractType): void
    {
        $total = '0.00';

        foreach ($template as $definition) {
            $total = bcadd($total, (string) $definition['percentage'], 2);
        }

        if (bccomp($total, '100.00', 2) !== 0) {
            throw new DomainException(sprintf(
                'Contract type "%s" bills %s%% across its milestones, not 100%%. A schedule that does not total the contract is one nobody notices until the final billing fails to balance.',
                $contractType,
                $total,
            ));
        }
    }
}
