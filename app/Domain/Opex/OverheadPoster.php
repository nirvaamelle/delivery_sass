<?php

namespace App\Domain\Opex;

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\LedgerPoster;
use App\Domain\Support\Money;
use App\Models\CostCode;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Overhead reaching `project_cost_ledger` — P4-07.
 *
 * The **fourth** chain to arrive at PLAN.md §1's organising table, after
 * material (P1-15), revenue (P2-09) and labour (P3-11). With this, every process
 * the deck describes ends where the plan says it should.
 *
 * Two decisions.
 *
 * **One row per cost code, not per expense.** A month of site utilities is forty
 * receipts; forty ledger rows would make the P&L unreadable and the reconciliation
 * against the budget line a summing exercise. The receipts stay individually
 * visible on `expenses` — the ledger carries the cost, and the expense carries
 * the evidence.
 *
 * **On the OPEX calendar.** Slide 8 cuts OPEX off on day 26, before the month it
 * closes has even finished, and F3's whole point is that "nothing books after
 * cutoff" means nothing until you say which cutoff.
 *
 * Each expense is stamped posted inside the same transaction, so a refused
 * posting cannot leave an expense claiming to be in a ledger that never took it
 * — the discipline every poster in this build follows.
 */
class OverheadPoster
{
    public function __construct(
        private readonly LedgerPoster $ledger,
    ) {}

    /**
     * Post a project's period, one ledger row per cost code.
     *
     * @return array<int, ProjectCostLedgerEntry>
     *
     * @throws DomainException when there is nothing left to post
     */
    public function postPeriod(Project $project, int $year, int $month, ?string $description = null): array
    {
        $expenses = Expense::query()
            ->where('project_id', $project->getKey())
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNotIn('status', [ExpenseStatus::Returned, ExpenseStatus::Posted])
            ->get();

        if ($expenses->isEmpty()) {
            throw new DomainException(sprintf(
                'Project %s has no unposted expenses in %d-%02d.',
                $project->code,
                $year,
                $month,
            ));
        }

        $byCostCode = [];

        foreach ($expenses as $expense) {
            $byCostCode[(int) $expense->cost_code_id][] = $expense;
        }

        ksort($byCostCode);

        return DB::transaction(function () use ($project, $byCostCode, $year, $month, $description): array {
            $entries = [];

            foreach ($byCostCode as $costCodeId => $group) {
                $costCode = CostCode::query()->findOrFail($costCodeId);

                $amount = '0.0000';

                foreach ($group as $expense) {
                    $amount = Money::sum($amount, (string) $expense->amount);
                }

                $entry = $this->ledger->post(
                    project: $project,
                    costCode: $costCode,
                    category: LedgerCategory::Overhead,
                    amount: $amount,
                    sourceDocument: $group[0],
                    documentNumber: sprintf('OPEX-%d-%02d-%s', $year, $month, $costCode->code),
                    // F3 — the OPEX calendar, which cuts off on day 26.
                    cutoffType: CutoffType::Opex,
                    documentDate: Carbon::create($year, $month, 1)->endOfMonth(),
                    description: $description ?? sprintf('Overhead: %s, %d-%02d', $costCode->name, $year, $month),
                );

                foreach ($group as $expense) {
                    $expense->update([
                        'status' => ExpenseStatus::Posted,
                        'posted_at' => now(),
                        'project_cost_ledger_entry_id' => $entry->getKey(),
                    ]);
                }

                $entries[] = $entry;
            }

            return $entries;
        });
    }
}
