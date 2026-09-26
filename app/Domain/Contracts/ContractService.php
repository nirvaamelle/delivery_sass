<?php

namespace App\Domain\Contracts;

use App\Domain\Billing\BillingScheduleService;
use App\Domain\Support\Money;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The client contract — the document the whole acquisition chain rests on.
 *
 * Contracts had no service and no screen: they were created by factories and
 * seeders only. Nothing else could start without one, because F14 refuses a
 * purchase requisition on a project with no SIGNED contract, and the billing
 * schedule is built from the contract's sum.
 *
 * **Signing is the act, not a dropdown.** The moment a contract is signed is the
 * moment the project may commit money, so it is recorded with its date and
 * cannot be undone by editing a status.
 *
 * **The sum, retention rate and defects liability period are fixed once signed.**
 * Retention is withheld from every invoice as a percentage of it, the schedule's
 * milestone amounts are computed from it, and the defects liability period dates
 * the warranty. Changing any of them afterwards silently restates money already
 * billed, withheld and released.
 *
 * PLACEHOLDER: Part D item 3 — the retention rate (10%) and the defects
 * liability period (365 days) are per contract, so answering is data entry.
 */
class ContractService
{
    private const REQUIRED = ['number', 'contract_sum'];

    /** What a draft may still change. */
    private const EDITABLE = ['number', 'contract_sum', 'retention_rate', 'defects_liability_days', 'noa_date', 'ntp_date'];

    public function __construct(private readonly BillingScheduleService $schedules) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function draft(Project $project, array $attributes, ?User $by = null): Contract
    {
        foreach (self::REQUIRED as $field) {
            if (blank($attributes[$field] ?? null)) {
                throw new InvalidContractDetail($field, sprintf('A contract needs %s.', str_replace('_', ' ', $field)));
            }
        }

        $number = trim((string) $attributes['number']);

        if (Contract::query()->where('number', $number)->exists()) {
            throw new InvalidContractDetail('number', sprintf('Contract number %s is already in use.', $number));
        }

        $values = $this->validated(array_intersect_key($attributes, array_flip(self::EDITABLE)));

        $contract = Contract::query()->create([
            ...$values,
            'project_id' => $project->getKey(),
            'number' => $number,
            'status' => ContractStatus::Draft,
        ]);

        activity()->performedOn($contract)->causedBy($by)->log('contract-drafted');

        return $contract->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(Contract $contract, array $attributes, ?User $by = null): Contract
    {
        $this->assertAmendable($contract);

        $locked = array_values(array_diff(array_keys($attributes), self::EDITABLE));

        if ($locked !== []) {
            throw new InvalidContractDetail($locked[0], sprintf(
                '%s cannot be changed here — the project is fixed, and signing and termination are their own acts.',
                $locked[0],
            ));
        }

        $contract->fill($this->validated($attributes));
        $changed = array_keys($contract->getDirty());
        $contract->save();

        if ($changed !== []) {
            activity()->performedOn($contract)->causedBy($by)
                ->withProperties(['fields' => $changed])
                ->log('contract-details-updated');
        }

        return $contract->refresh();
    }

    /**
     * Send the contract out for signature. Nothing about it changes after this
     * except by returning it to draft.
     */
    public function sendForSignature(Contract $contract, ?User $by = null): Contract
    {
        if ($contract->status !== ContractStatus::Draft) {
            throw new DomainException(sprintf('Contract %s is %s; only a draft goes out for signature.', $contract->number, $contract->status->value));
        }

        $contract->update(['status' => ContractStatus::ForSignature]);

        activity()->performedOn($contract)->causedBy($by)->log('contract-sent-for-signature');

        return $contract->refresh();
    }

    public function returnToDraft(Contract $contract, string $reason, ?User $by = null): Contract
    {
        if ($contract->status !== ContractStatus::ForSignature) {
            throw new DomainException(sprintf('Contract %s is %s; only one out for signature comes back to draft.', $contract->number, $contract->status->value));
        }

        if (trim($reason) === '') {
            throw new DomainException('Returning a contract to draft needs a reason.');
        }

        $contract->update(['status' => ContractStatus::Draft]);

        activity()->performedOn($contract)->causedBy($by)
            ->withProperties(['reason' => trim($reason)])
            ->log('contract-returned-to-draft');

        return $contract->refresh();
    }

    /**
     * Sign it, and build the billing schedule in the same transaction.
     *
     * **The schedule is built here rather than left to somebody to remember.**
     * Billing cannot be submitted without a milestone, and a signed contract
     * with no schedule is a project that can spend but not bill.
     *
     * @throws DomainException when the contract is not out for signature, or the
     *                         contract type has no configured milestones
     */
    public function sign(Contract $contract, CarbonInterface $signedAt, string $contractType = 'default', ?User $by = null): Contract
    {
        if ($contract->status !== ContractStatus::ForSignature) {
            throw new DomainException(sprintf(
                'Contract %s is %s. A contract is signed after it goes out for signature, so that what was signed is what was sent.',
                $contract->number,
                $contract->status->value,
            ));
        }

        return DB::transaction(function () use ($contract, $signedAt, $contractType, $by): Contract {
            $contract->update([
                'status' => ContractStatus::Signed,
                'signed_at' => $signedAt,
            ]);

            // Refused for an unknown type, which fails the whole signing rather
            // than leaving a signed contract nobody can bill against.
            $this->schedules->createFor($contract->refresh(), $contractType);

            activity()->performedOn($contract)->causedBy($by)
                ->withProperties(['signed_at' => $signedAt->toDateString(), 'contract_type' => $contractType])
                ->log('contract-signed');

            return $contract->refresh();
        });
    }

    /**
     * End a contract before completion.
     */
    public function terminate(Contract $contract, string $reason, ?User $by = null): Contract
    {
        if ($contract->status === ContractStatus::Terminated) {
            throw new DomainException(sprintf('Contract %s is already terminated.', $contract->number));
        }

        if (trim($reason) === '') {
            throw new DomainException('Terminating a contract needs a reason.');
        }

        $contract->update(['status' => ContractStatus::Terminated]);

        activity()->performedOn($contract)->causedBy($by)
            ->withProperties(['reason' => trim($reason)])
            ->log('contract-terminated');

        return $contract->refresh();
    }

    private function assertAmendable(Contract $contract): void
    {
        if ($contract->status !== ContractStatus::Draft) {
            throw new DomainException(sprintf(
                'Contract %s is %s. Its terms are what the billing schedule, retention and warranty all date from, so they are fixed once it leaves draft.',
                $contract->number,
                $contract->status->value,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        if (array_key_exists('contract_sum', $attributes)) {
            $sum = (string) $attributes['contract_sum'];

            if (! is_numeric($sum) || bccomp($sum, '0', Money::SCALE) <= 0) {
                throw new InvalidContractDetail('contract_sum', 'A contract sum must be above zero.');
            }

            $attributes['contract_sum'] = bcadd($sum, '0', Money::SCALE);
        }

        if (array_key_exists('retention_rate', $attributes) && filled($attributes['retention_rate'])) {
            $rate = (string) $attributes['retention_rate'];

            // A rate above one is a percentage typed into a fraction field, which
            // would withhold more than the invoice is worth.
            if (! is_numeric($rate) || bccomp($rate, '0', 6) < 0 || bccomp($rate, '1', 6) > 0) {
                throw new InvalidContractDetail('retention_rate', 'Retention is a fraction between 0 and 1 — 0.10 for ten per cent.');
            }

            $attributes['retention_rate'] = bcadd($rate, '0', 6);
        }

        if (array_key_exists('defects_liability_days', $attributes) && filled($attributes['defects_liability_days'])
            && (! ctype_digit((string) $attributes['defects_liability_days']) || (int) $attributes['defects_liability_days'] < 1)) {
            throw new InvalidContractDetail('defects_liability_days', 'The defects liability period is a whole number of days, at least one.');
        }

        return $attributes;
    }
}
