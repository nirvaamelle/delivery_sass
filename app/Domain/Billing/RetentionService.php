<?php

namespace App\Domain\Billing;

use App\Domain\Support\Money;
use App\Models\Contract;
use App\Models\Project;
use App\Models\RetentionEntry;
use App\Models\SalesInvoice;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The retention ledger — slide 6's "10% withheld on every billing, released
 * after the defects liability period".
 *
 * **Retention is a ledger, not a column.** What a contractor actually asks is
 * "how much of our money is the client still holding, and from which billings" —
 * and only movements answer that. A single `retention_held` figure on the
 * contract can be made to agree with itself while agreeing with nothing else,
 * and partial releases cannot be expressed in it at all.
 *
 * **Release is a date, not a decision.** The defects liability period is
 * contractual: releasing early means asking for money the client has not agreed
 * to release, and the refusal arrives after somebody has already counted it as
 * collectible. A contract with no completion date has not started its DLP, so it
 * certainly has not finished one — and a missing date is not permission, the
 * same rule the cutoff calendar follows.
 */
class RetentionService
{
    /**
     * Record the retention withheld on an invoiced billing.
     *
     * @throws DomainException when this billing's retention is already on the
     *                         ledger
     */
    public function withhold(SalesInvoice $invoice, ?User $by = null): RetentionEntry
    {
        $billing = $invoice->billing()->sole();

        $existing = RetentionEntry::query()
            ->where('billing_id', $billing->getKey())
            ->where('type', RetentionEntryType::Withheld)
            ->exists();

        if ($existing) {
            throw new DomainException(sprintf(
                'Retention for billing %s is already on the ledger. A second entry doubles what the company believes the client is holding.',
                $billing->number,
            ));
        }

        $amount = (string) $invoice->retention_amount;

        if (Money::isZero($amount)) {
            throw new DomainException(sprintf(
                'Invoice %s withheld no retention, so there is nothing to record.',
                $invoice->number,
            ));
        }

        return RetentionEntry::query()->create([
            'project_id' => $invoice->project_id,
            'contract_id' => $billing->contract_id,
            'billing_id' => $billing->getKey(),
            'type' => RetentionEntryType::Withheld,
            'amount' => $amount,
            'entry_date' => $invoice->issued_on,
            'reference' => $invoice->number,
            'recorded_by_user_id' => $by?->getKey(),
        ]);
    }

    /**
     * Release retention after the defects liability period.
     *
     * @throws DomainException when the DLP has not ended, the amount exceeds
     *                         what is held, or no reference is given
     */
    public function release(Contract $contract, string $amount, string $reference, ?User $by = null): RetentionEntry
    {
        if (trim($reference) === '') {
            throw new DomainException(
                'A retention release needs a reference. It is the document the client pays against, and an unreferenced release is one nobody can chase.'
            );
        }

        $endsOn = $this->defectsLiabilityEndsOn($contract);

        if ($endsOn === null) {
            throw new DomainException(sprintf(
                'Contract %s has no completion date, so its defects liability period has not started. A missing date is not permission.',
                $contract->number,
            ));
        }

        if (now()->startOfDay()->lt($endsOn)) {
            throw new DomainException(sprintf(
                'The defects liability period on contract %s runs until %s. Releasing retention early asks the client for money they have not agreed to release.',
                $contract->number,
                $endsOn->toDateString(),
            ));
        }

        $project = $contract->project()->sole();
        $balance = $this->balanceFor($project);

        if (Money::greaterThan($amount, $balance)) {
            throw new DomainException(sprintf(
                'Retention held is %s; this release is for %s. Releasing more than was withheld invents money the client never held.',
                $balance,
                $amount,
            ));
        }

        return RetentionEntry::query()->create([
            'project_id' => $project->getKey(),
            'contract_id' => $contract->getKey(),
            'type' => RetentionEntryType::Released,
            // Negative: the balance is a sum rather than a difference somebody
            // can get backwards.
            'amount' => bcsub('0', $amount, Money::SCALE),
            'entry_date' => now(),
            'reference' => $reference,
            'recorded_by_user_id' => $by?->getKey(),
        ]);
    }

    /**
     * What the client is still holding on this project.
     */
    public function balanceFor(Project $project): string
    {
        $balance = '0.0000';

        foreach ($this->entriesFor($project)->pluck('amount') as $amount) {
            $balance = Money::sum($balance, (string) $amount);
        }

        return $balance;
    }

    /**
     * @return Collection<int, RetentionEntry>
     */
    public function entriesFor(Project $project): Collection
    {
        return RetentionEntry::query()
            ->where('project_id', $project->getKey())
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * When the defects liability period ends, if the works have been accepted.
     */
    public function defectsLiabilityEndsOn(Contract $contract): ?Carbon
    {
        if ($contract->completed_on === null) {
            return null;
        }

        return $contract->completed_on->copy()->addDays((int) $contract->defects_liability_days);
    }
}
