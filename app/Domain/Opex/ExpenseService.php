<?php

namespace App\Domain\Opex;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Support\Money;
use App\Models\BudgetLine;
use App\Models\CostCode;
use App\Models\Expense;
use App\Models\ExpensePeriodBar;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Expense capture — slide 8's day 1–25 stage, and its one hard control.
 *
 * **"An expense with no receipt, no cost code, or no budget line is not booked —
 * returned to the site the same day, and cannot be charged to the project
 * later."**
 *
 * The first three are refusals in front of capture. The cost code is the
 * strongest because it is a non-nullable foreign key rather than a check: no
 * importer, console command or queued job can create an uncoded expense, which
 * is PLAN.md §5's argument about controls that live only in a service.
 *
 * The fourth clause is the task. PHASE-PLAN.md reads "cannot be charged later"
 * as **barred from that period**, and the period is the whole nuance. A returned
 * expense is usually a missing receipt or an uncoded line — not fiction — so the
 * site corrects it and books it against the NEXT period, which is legitimate.
 * What must not happen is the same receipt reappearing in a period whose numbers
 * have already been reported.
 *
 * The bar is keyed on the **receipt**, not the amount: two different bills for
 * the same amount in one month is ordinary, and keying on the amount would block
 * the second one.
 */
class ExpenseService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
    ) {}

    /**
     * Capture an expense — slide 8's three refusals run here.
     *
     * @throws PeriodBarredException when this receipt was returned from this period
     * @throws DomainException when the receipt, cost code or budget line is missing
     */
    public function capture(
        Project $project,
        CostCode $costCode,
        string $amount,
        CarbonInterface $incurredOn,
        string $description,
        string $receiptReference,
        ?User $by = null,
        LedgerCategory $category = LedgerCategory::Overhead,
        ?string $payee = null,
    ): Expense {
        if (trim($receiptReference) === '') {
            throw new DomainException(
                'An expense with no receipt is not booked. It is a number somebody remembers, and the first one an auditor asks about.'
            );
        }

        if (Money::isZero($amount) || bccomp($amount, '0', Money::SCALE) < 0) {
            throw new DomainException('An expense must be a positive amount.');
        }

        if ((int) $costCode->organization_id !== (int) $project->organization_id) {
            // Cross-organization coding puts one company's cost into another
            // company's P&L, and every row still looks well-formed.
            throw new DomainException(sprintf(
                'Cost code %s belongs to another organization than project %s.',
                $costCode->code,
                $project->code,
            ));
        }

        if (! $this->hasBudgetLine($project, $costCode)) {
            // An unbudgeted cost code has availability of zero, not unlimited —
            // the rule P0-17 established for requisitions, applied to OPEX.
            throw new DomainException(sprintf(
                'Cost code %s has no budget line on project %s. An unbudgeted code is not a limitless one.',
                $costCode->code,
                $project->code,
            ));
        }

        $year = (int) $incurredOn->year;
        $month = (int) $incurredOn->month;

        $this->assertNotBarred($project, $receiptReference, $year, $month);

        return DB::transaction(fn (): Expense => Expense::query()->create([
            'project_id' => $project->getKey(),
            'cost_code_id' => $costCode->getKey(),
            'number' => $this->numbering->next('EXP'),
            'status' => ExpenseStatus::Captured,
            'category' => $category,
            'amount' => $amount,
            'incurred_on' => $incurredOn,
            // The DOCUMENT's date decides the period. An expense dated 3 May and
            // booked on 27 May belongs to May, and is late.
            'period_year' => $year,
            'period_month' => $month,
            'receipt_reference' => $receiptReference,
            'description' => $description,
            'payee' => $payee,
            'captured_by_user_id' => $by?->getKey(),
        ]));
    }

    /**
     * Return an expense to the site, and bar its receipt from the period.
     *
     * @throws DomainException when no reason is given, or it is already returned
     */
    public function returnToSite(Expense $expense, string $reason, ?User $by = null): Expense
    {
        if ($expense->status === ExpenseStatus::Returned) {
            throw new DomainException(sprintf('Expense %s was already returned.', $expense->number));
        }

        if ($expense->status === ExpenseStatus::Posted) {
            // A posted expense is in the ledger, which is append-only. The
            // correction is a reversing entry, not a return.
            throw new DomainException(sprintf(
                'Expense %s is already in the ledger. Correct it with a reversing entry rather than a return.',
                $expense->number,
            ));
        }

        if (trim($reason) === '') {
            throw new DomainException(
                'A returned expense needs a reason. It is what the site corrects against, and without it the return is a rejection nobody can act on.'
            );
        }

        return DB::transaction(function () use ($expense, $reason, $by): Expense {
            $expense->update([
                'status' => ExpenseStatus::Returned,
                'return_reason' => $reason,
                'returned_at' => now(),
                'returned_by_user_id' => $by?->getKey(),
            ]);

            ExpensePeriodBar::query()->create([
                'project_id' => $expense->project_id,
                'receipt_reference' => $expense->receipt_reference,
                'period_year' => $expense->period_year,
                'period_month' => $expense->period_month,
                'expense_id' => $expense->getKey(),
                'reason' => $reason,
                'barred_at' => now(),
                'barred_by_user_id' => $by?->getKey(),
            ]);

            return $expense->refresh();
        });
    }

    /**
     * Lift a bar that was raised in error.
     *
     * @throws DomainException when nothing is barred, or no note is given
     */
    public function clearBar(
        Project $project,
        string $receiptReference,
        int $year,
        int $month,
        string $note,
        ?User $by = null,
    ): ExpensePeriodBar {
        if (trim($note) === '') {
            throw new DomainException(
                'Clearing a bar needs a note. Lifting it is an act with a name on it, not a side effect of trying again.'
            );
        }

        $bar = $this->activeBar($project, $receiptReference, $year, $month);

        if ($bar === null) {
            throw new DomainException(sprintf(
                'Receipt %s is not barred from %d-%02d on this project.',
                $receiptReference,
                $year,
                $month,
            ));
        }

        $bar->update([
            'cleared_at' => now(),
            'cleared_by_user_id' => $by?->getKey(),
            'clearance_note' => $note,
        ]);

        return $bar->refresh();
    }

    /**
     * Receipts this project may not book against a period.
     *
     * @return array<int, string>
     */
    public function barredReceipts(Project $project, int $year, int $month): array
    {
        return ExpensePeriodBar::query()
            ->where('project_id', $project->getKey())
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNull('cleared_at')
            ->pluck('receipt_reference')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Total captured expense on a project for a period.
     *
     * Summed in bcmath rather than SQL SUM(), the same discipline as every other
     * total in this build — the figure feeds the budget-versus-actual comparison
     * and the month-end close.
     */
    public function totalFor(Project $project, int $year, int $month, ?CostCode $costCode = null): string
    {
        $query = Expense::query()
            ->where('project_id', $project->getKey())
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNot('status', ExpenseStatus::Returned);

        if ($costCode !== null) {
            $query->where('cost_code_id', $costCode->getKey());
        }

        $total = '0.0000';

        foreach ($query->pluck('amount') as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    private function activeBar(Project $project, string $receiptReference, int $year, int $month): ?ExpensePeriodBar
    {
        return ExpensePeriodBar::query()
            ->where('project_id', $project->getKey())
            ->where('receipt_reference', $receiptReference)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNull('cleared_at')
            ->orderByDesc('barred_at')
            ->first();
    }

    /**
     * @throws PeriodBarredException
     */
    private function assertNotBarred(Project $project, string $receiptReference, int $year, int $month): void
    {
        $bar = $this->activeBar($project, $receiptReference, $year, $month);

        if ($bar !== null) {
            throw new PeriodBarredException(sprintf(
                'Receipt %s was returned from %d-%02d: %s. Slide 8 bars it from that period — book the corrected expense against the next one.',
                $receiptReference,
                $year,
                $month,
                $bar->reason,
            ));
        }
    }

    private function hasBudgetLine(Project $project, CostCode $costCode): bool
    {
        return BudgetLine::query()
            ->where('cost_code_id', $costCode->getKey())
            ->whereIn('budget_id', $project->budgets()->pluck('id'))
            ->exists();
    }
}
