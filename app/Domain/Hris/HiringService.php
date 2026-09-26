<?php

namespace App\Domain\Hris;

use App\Domain\Gates\GateFailedException;
use App\Domain\Gates\Gatekeeper;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\ManpowerRequisition;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manpower requisition and the employment contract — P3-02 and P3-03.
 *
 * **Slide 7's rule: "Employment contract signed BEFORE first shift."**
 *
 * That is a gate, not a filing instruction, and it is the reason these two tasks
 * are one service. Somebody who works an unsigned shift has still worked it: the
 * company owes them for it and owes the statutory contributions on it, while
 * holding nothing that records the terms. Every incentive on a busy site pushes
 * toward letting them start and doing the paperwork on Friday — which is exactly
 * why the rule has to live somewhere a busy Friday cannot reach.
 *
 * Two consequences worth stating.
 *
 * **A signature cannot be backdated past the start date.** Signing on the 5th a
 * contract that began on the 1st is the paperwork being caught up on, which is
 * the practice the rule exists to stop; recording it as compliant would make the
 * gate a formality. It is refused.
 *
 * **Signing is what starts the rate history.** The contract is where the rate is
 * agreed, so it is where `employee_rates` begins. Typing the rate separately
 * afterwards is how the 201 file and the signed terms come to disagree, and the
 * 201 file is what payroll reads.
 */
class HiringService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly Gatekeeper $gates,
        private readonly EmployeeService $employees,
    ) {}

    /**
     * Ask for people — slide 7's step 1.
     *
     * @throws DomainException when the headcount is not positive
     */
    public function requestManpower(
        Project $project,
        string $position,
        int $headcount,
        CarbonInterface $requiredBy,
        ?string $justification = null,
        ?User $by = null,
    ): ManpowerRequisition {
        if ($headcount < 1) {
            throw new DomainException(
                'A manpower requisition for nobody is a request for nothing, and it would still consume an approver\'s attention.'
            );
        }

        return DB::transaction(fn (): ManpowerRequisition => ManpowerRequisition::query()->create([
            'project_id' => $project->getKey(),
            'number' => $this->numbering->next('MPR'),
            'status' => ManpowerStatus::Requested,
            'position' => $position,
            'headcount' => $headcount,
            'required_by' => $requiredBy,
            'justification' => $justification,
            'requested_by_user_id' => $by?->getKey(),
        ]));
    }

    /**
     * @throws DomainException when it has already been decided
     */
    public function approveManpower(ManpowerRequisition $requisition, User $by, ?string $remarks = null): ManpowerRequisition
    {
        if ($requisition->status !== ManpowerStatus::Requested) {
            throw new DomainException(sprintf(
                'Manpower requisition %s is %s. Approving it again would let one request justify two intakes.',
                $requisition->number,
                $requisition->status->value,
            ));
        }

        $requisition->update([
            'status' => ManpowerStatus::Approved,
            'approved_at' => now(),
            'approved_by_user_id' => $by->getKey(),
            'approval_remarks' => $remarks,
        ]);

        return $requisition->refresh();
    }

    /**
     * Issue a contract. It starts UNSIGNED, and that is the point.
     *
     * @throws DomainException when the rate is not positive
     */
    public function issueContract(
        Employee $employee,
        CarbonInterface $effectiveFrom,
        ?CarbonInterface $effectiveTo,
        PayBasis $basis,
        string $rate,
        ?ManpowerRequisition $requisition = null,
        ?string $position = null,
    ): EmploymentContract {
        if (bccomp($rate, '0.0000', Money::SCALE) <= 0) {
            throw new DomainException('A contract rate of zero is not terms anybody can agree to.');
        }

        return DB::transaction(fn (): EmploymentContract => EmploymentContract::query()->create([
            'employee_id' => $employee->getKey(),
            'manpower_requisition_id' => $requisition?->getKey(),
            'number' => $this->numbering->next('EC'),
            'status' => ContractStatus::Issued,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'pay_basis' => $basis,
            'rate' => $rate,
            'position' => $position ?? $employee->position,
        ]));
    }

    /**
     * Record the signature, and start the rate history from it.
     *
     * @throws DomainException when already signed, unsigned by anybody nameable,
     *                         or dated after the contract had already begun
     */
    public function signContract(
        EmploymentContract $contract,
        CarbonInterface $signedOn,
        string $signatory,
        ?User $witness = null,
    ): EmploymentContract {
        if ($contract->status !== ContractStatus::Issued) {
            throw new DomainException(sprintf(
                'Contract %s is %s, not awaiting signature.',
                $contract->number,
                $contract->status->value,
            ));
        }

        if (trim($signatory) === '') {
            // The same argument as the purchase order's countersignature:
            // "somebody signed" cannot settle a dispute about terms.
            throw new DomainException('A contract signature needs the name of whoever signed it.');
        }

        if ($signedOn->gt($contract->effective_from)) {
            throw new DomainException(sprintf(
                'Contract %s began %s and this signature is dated %s. "Signed before first shift" is the rule; recording a late signature as compliant would make the rule a formality.',
                $contract->number,
                $contract->effective_from->toDateString(),
                $signedOn->toDateString(),
            ));
        }

        return DB::transaction(function () use ($contract, $signedOn, $signatory, $witness): EmploymentContract {
            $contract->update([
                'status' => ContractStatus::Signed,
                'signed_on' => $signedOn,
                'signed_at' => now(),
                'signed_by' => $signatory,
                'witnessed_by_user_id' => $witness?->getKey(),
            ]);

            // The contract is where the rate was agreed, so it is where the rate
            // history starts. Recorded separately, the 201 file and the signed
            // terms drift — and payroll reads the 201 file.
            $this->employees->setRate(
                $contract->employee()->sole(),
                $contract->pay_basis,
                (string) $contract->rate,
                $contract->effective_from,
                $witness,
                sprintf('Per contract %s.', $contract->number),
            );

            return $contract->refresh();
        });
    }

    /**
     * Slide 7's gate, as a refusal.
     *
     * @throws GateFailedException when no signed contract covers that day
     */
    public function assertMayWork(Employee $employee, CarbonInterface $date): void
    {
        // The Gatekeeper's contract takes one subject, and this question is
        // about a subject AND a date. Carried on the model rather than widening
        // the interface for one caller.
        $employee->assertion_date = Carbon::parse($date->toDateString());

        $this->gates->assert($employee, HiringTransition::Work->value);
    }

    /**
     * The same question, asked rather than asserted.
     */
    public function mayWork(Employee $employee, CarbonInterface $date): bool
    {
        try {
            $this->assertMayWork($employee, $date);

            return true;
        } catch (GateFailedException) {
            return false;
        }
    }

    /**
     * The contract covering a given day, if there is one.
     */
    public function contractOn(Employee $employee, CarbonInterface $date): ?EmploymentContract
    {
        $day = Carbon::parse($date->toDateString());

        return $employee->employmentContracts()
            ->where('status', ContractStatus::Signed)
            ->whereDate('effective_from', '<=', $day)
            ->where(function ($query) use ($day): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $day);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
