<?php

namespace App\Domain\Opex;

use App\Domain\Billing\AccomplishmentService;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Closeout\FinalAccountService;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Support\Money;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Project;

/**
 * Cost per unit of accomplishment — PLAN.md §7 step 9.
 *
 * "Project P&L and cost per unit of accomplishment, assembled from all four
 * chains." The P&L arrived in P4-08. This did not: the phrase sat in docblocks
 * from P1-15 onward, quoted by `ConsolidationService` itself, and was found
 * missing only when the Phase 6 exit gate walked §7 step by step.
 *
 * **The unit is one percentage point of VERIFIED accomplishment.** Cost to date
 * divided by how far along both sides have signed that the works are. A
 * measurement the client has not countersigned is invisible here for the same
 * reason it is invisible to the billing gate: an unagreed percentage would let
 * the figure be improved simply by measuring optimistically.
 *
 * **Nothing verified means not measurable — never zero, never a division by
 * zero.** Zero cost per point reads as free work. Absence is not permission.
 *
 * **Benchmarked against the OPEN budget**, so the figure says whether it is
 * good: a 3,000,000 budget plans 30,000 per point, and a project spending 33,000
 * per point is ten percent over — visible at 40% complete, not at handover.
 *
 * Life to date, read from the ledger through `FinalAccountService`, so this and
 * the final account can never disagree about what a project has cost.
 */
class CostPerUnitService
{
    /** The ledger categories that are cost. Revenue is not. */
    private const COST_CATEGORIES = [
        LedgerCategory::Material,
        LedgerCategory::Subcontract,
        LedgerCategory::Labor,
        LedgerCategory::Overhead,
    ];

    public function __construct(
        private readonly FinalAccountService $accounts,
        private readonly AccomplishmentService $accomplishments,
    ) {}

    /**
     * @return array{cost_to_date: string, verified_percent: string, measurable: bool, cost_per_percent: ?string, budget: string, budgeted_cost_per_percent: ?string, variance_percent: ?string}
     */
    public function forProject(Project $project): array
    {
        $cost = '0.0000';

        foreach (self::COST_CATEGORIES as $category) {
            $cost = Money::sum($cost, $this->accounts->lifetimeTotal($project, $category));
        }

        $verified = $this->accomplishments->verifiedPercentageFor($project);
        $measurable = bccomp($verified, '0', 2) > 0;

        $costPerPercent = $measurable
            ? Money::round(bcdiv($cost, $verified, Money::WORKING_SCALE))
            : null;

        $budget = $this->openBudgetTotal($project);

        // A project with no open budget has no plan to compare against, which
        // is a different statement from "on plan" and must not read as one.
        $budgetedPerPercent = Money::isZero($budget)
            ? null
            : Money::round(bcdiv($budget, '100', Money::WORKING_SCALE));

        $variance = ($costPerPercent !== null && $budgetedPerPercent !== null)
            ? bcdiv(
                bcmul(bcsub($costPerPercent, $budgetedPerPercent, Money::WORKING_SCALE), '100', Money::WORKING_SCALE),
                $budgetedPerPercent,
                2,
            )
            : null;

        return [
            'cost_to_date' => $cost,
            'verified_percent' => $verified,
            'measurable' => $measurable,
            'cost_per_percent' => $costPerPercent,
            'budget' => $budget,
            'budgeted_cost_per_percent' => $budgetedPerPercent,
            'variance_percent' => $variance,
        ];
    }

    /**
     * The open budget, and only the open budget. A draft is somebody's working,
     * not the number the project is held to.
     */
    private function openBudgetTotal(Project $project): string
    {
        $total = '0.0000';

        $amounts = BudgetLine::query()
            ->whereIn('budget_id', Budget::query()
                ->where('project_id', $project->getKey())
                ->where('status', BudgetStatus::Open)
                ->select('id'))
            ->pluck('amount');

        foreach ($amounts as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }
}
