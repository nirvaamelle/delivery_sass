<?php

namespace App\Domain\Opex;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\CashAdvance;
use App\Models\CashAdvanceLiquidation;
use App\Models\CostCode;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash advances and liquidation — P4-02.
 *
 * Slide 8's day-26 rule is that advances are "liquidated **or charged to the next
 * payroll**". F17 is the charging half and it is scheduled; this service is the
 * record that job reads.
 *
 * **The outstanding balance is derived, never stored.** The same rule the stock
 * card follows and for the same reason: a stored figure is only as true as the
 * last routine that wrote it, and the day that routine fails quietly is the day
 * somebody's advance is charged to payroll twice or not at all. The balance is
 * what was released less what has been liquidated, and only the liquidations
 * answer that.
 *
 * **A liquidation points at a real expense.** Advanced money is accounted for by
 * producing the receipt, which goes through `ExpenseService` and therefore
 * through slide 8's own three refusals — no receipt, no cost code, no budget
 * line. A liquidation with a bare figure behind it is an advance written off by
 * assertion.
 *
 * The exception is a **cash return**, which has no expense because nothing was
 * bought. Somebody drew ₱10,000, spent ₱8,400 and handed back ₱1,600; forcing
 * that into an expense would mean inventing one for the change in their pocket.
 */
class CashAdvanceService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly ExpenseService $expenses,
    ) {}

    /**
     * Release an advance.
     *
     * @throws DomainException when the amount is not positive or no purpose is given
     */
    public function release(
        Employee $employee,
        Project $project,
        string $amount,
        CarbonInterface $releasedOn,
        string $purpose,
        ?User $by = null,
    ): CashAdvance {
        if (bccomp($amount, '0', Money::SCALE) <= 0) {
            throw new DomainException('A cash advance must be a positive amount.');
        }

        if (trim($purpose) === '') {
            throw new DomainException(
                'A cash advance needs a purpose. Money leaving before anything is spent is the payment an auditor opens first.'
            );
        }

        return DB::transaction(fn (): CashAdvance => CashAdvance::query()->create([
            'employee_id' => $employee->getKey(),
            'project_id' => $project->getKey(),
            'number' => $this->numbering->next('CA'),
            'status' => CashAdvanceStatus::Released,
            'amount' => $amount,
            'released_on' => $releasedOn,
            'purpose' => $purpose,
            'released_by_user_id' => $by?->getKey(),
        ]));
    }

    /**
     * Account for part of an advance by capturing the expense it paid for.
     *
     * The expense goes through `ExpenseService`, so slide 8's refusals apply to
     * advanced money exactly as they do to anything else.
     *
     * @throws DomainException when the advance is closed, the liquidation exceeds
     *                         what is outstanding, or it predates the advance
     */
    public function liquidateWithExpense(
        CashAdvance $advance,
        CostCode $costCode,
        string $amount,
        CarbonInterface $incurredOn,
        string $description,
        string $receiptReference,
        ?User $by = null,
    ): CashAdvanceLiquidation {
        $this->assertLiquidatable($advance, $amount, $incurredOn);

        return DB::transaction(function () use ($advance, $costCode, $amount, $incurredOn, $description, $receiptReference, $by): CashAdvanceLiquidation {
            $expense = $this->expenses->capture(
                $advance->project()->sole(),
                $costCode,
                $amount,
                $incurredOn,
                $description,
                $receiptReference,
                $by,
            );

            $liquidation = CashAdvanceLiquidation::query()->create([
                'cash_advance_id' => $advance->getKey(),
                'expense_id' => $expense->getKey(),
                'amount' => $amount,
                'liquidated_on' => $incurredOn,
                'recorded_by_user_id' => $by?->getKey(),
            ]);

            $this->settle($advance->refresh());

            return $liquidation;
        });
    }

    /**
     * Account for part of an advance by handing the cash back.
     *
     * @throws DomainException when the advance is closed, the return exceeds what
     *                         is outstanding, or no reference is given
     */
    public function returnCash(
        CashAdvance $advance,
        string $amount,
        CarbonInterface $returnedOn,
        string $reference,
        ?User $by = null,
    ): CashAdvanceLiquidation {
        $this->assertLiquidatable($advance, $amount, $returnedOn);

        if (trim($reference) === '') {
            throw new DomainException('A cash return needs a reference — the receipt the company issued for the money coming back.');
        }

        return DB::transaction(function () use ($advance, $amount, $returnedOn, $reference, $by): CashAdvanceLiquidation {
            $liquidation = CashAdvanceLiquidation::query()->create([
                'cash_advance_id' => $advance->getKey(),
                'cash_return_reference' => $reference,
                'amount' => $amount,
                'liquidated_on' => $returnedOn,
                'recorded_by_user_id' => $by?->getKey(),
            ]);

            $this->settle($advance->refresh());

            return $liquidation;
        });
    }

    /**
     * What is still unaccounted for on this advance.
     */
    public function outstandingFor(CashAdvance $advance): string
    {
        $liquidated = '0.0000';

        foreach ($advance->liquidations()->pluck('amount') as $amount) {
            $liquidated = Money::sum($liquidated, (string) $amount);
        }

        return bcsub((string) $advance->amount, $liquidated, Money::SCALE);
    }

    /**
     * Advances still open as at a date — what F17's day-26 job sweeps.
     *
     * Takes a date rather than reading "now" because the job runs on the 26th and
     * must not sweep an advance released on the 27th into a cutoff it has already
     * passed.
     *
     * @return Collection<int, CashAdvance>
     */
    public function unliquidatedAsOf(Organization $organization, CarbonInterface $asOf): Collection
    {
        return CashAdvance::query()
            ->whereIn('status', [CashAdvanceStatus::Released, CashAdvanceStatus::PartlyLiquidated])
            ->whereDate('released_on', '<=', $asOf)
            ->whereIn('employee_id', Employee::query()
                ->where('organization_id', $organization->getKey())
                ->select('id'))
            ->orderBy('released_on')
            ->get()
            ->filter(fn (CashAdvance $advance): bool => ! Money::isZero($this->outstandingFor($advance)))
            ->values();
    }

    /**
     * Move the advance to its settled state once nothing is outstanding.
     */
    private function settle(CashAdvance $advance): void
    {
        $outstanding = $this->outstandingFor($advance);

        $advance->update([
            'status' => Money::isZero($outstanding)
                ? CashAdvanceStatus::Liquidated
                : CashAdvanceStatus::PartlyLiquidated,
        ]);
    }

    /**
     * @throws DomainException
     */
    private function assertLiquidatable(CashAdvance $advance, string $amount, CarbonInterface $on): void
    {
        if (! $advance->status->isOpen()) {
            throw new DomainException(sprintf(
                'Cash advance %s is %s and has nothing left to account for.',
                $advance->number,
                $advance->status->value,
            ));
        }

        if (bccomp($amount, '0', Money::SCALE) <= 0) {
            throw new DomainException('A liquidation must be a positive amount.');
        }

        if ($on->lt($advance->released_on)) {
            // A receipt older than the money cannot have been paid for with it.
            throw new DomainException(sprintf(
                'Cash advance %s was released on %s; this liquidation is dated %s.',
                $advance->number,
                $advance->released_on->toDateString(),
                $on->toDateString(),
            ));
        }

        $outstanding = $this->outstandingFor($advance);

        if (Money::greaterThan($amount, $outstanding)) {
            // Over-liquidation turns an advance into a reimbursement, which is a
            // different decision with a different approval behind it.
            throw new DomainException(sprintf(
                'Cash advance %s has %s outstanding; this liquidation is for %s.',
                $advance->number,
                $outstanding,
                $amount,
            ));
        }
    }
}
