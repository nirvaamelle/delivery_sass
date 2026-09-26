<?php

namespace App\Domain\Reporting;

use App\Domain\Billing\AgingBucket;
use App\Domain\Billing\ArAgingService;
use App\Domain\Billing\RetentionService;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Procurement\PayablesService;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Support\Money;
use App\Models\ArEscalation;
use App\Models\BudgetLine;
use App\Models\OpexVarianceExplanation;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use App\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The figures behind the dashboard.
 *
 * Every role signed in to a blank page until this existed. PLAN.md §2 names the
 * widgets — S-curve, budget versus actual, cost per unit of accomplishment — and
 * the dashboard shipped with none of them.
 *
 * **Nothing here is stored, and nothing is summed in SQL.** Every figure is
 * assembled from the ledger in bcmath at the moment it is asked for. A stored
 * KPI drifts from the ledger it claims to summarise, and a drifted KPI is worse
 * than no KPI at all, because somebody will act on it. The cost is a handful of
 * queries per page load, which is the right trade at this size; if the ledger
 * grows past the point where that is comfortable, the answer is a materialised
 * summary written by the posting service, not a SUM() over a decimal cast.
 *
 * **It reads through the project scope.** `Project::query()` is scoped globally
 * (P6-01), so a project manager's dashboard adds up their own jobs. Nothing here
 * lifts that scope: a headline figure must not leak a company total that every
 * screen would refuse to show the same person.
 */
class KpiService
{
    /** What the ledger counts as cost, as opposed to revenue. */
    private const COST_CATEGORIES = [
        LedgerCategory::Material,
        LedgerCategory::Subcontract,
        LedgerCategory::Labor,
        LedgerCategory::Overhead,
    ];

    public function __construct(
        private readonly ArAgingService $ageing,
        private readonly RetentionService $retention,
        private readonly PayablesService $payables,
    ) {}

    /**
     * Revenue, cost, profit and margin across every project in view.
     *
     * @return array{revenue: string, cost: string, gross_profit: string, margin_percent: ?string, active_projects: int, contract_value: string}
     */
    public function portfolio(): array
    {
        $projects = Project::query()->get();
        $projectIds = $projects->modelKeys();

        $revenue = $this->ledgerTotal($projectIds, [LedgerCategory::Revenue]);
        $cost = $this->ledgerTotal($projectIds, self::COST_CATEGORIES);
        $grossProfit = bcsub($revenue, $cost, Money::SCALE);

        $contractValue = '0.0000';

        foreach ($projects as $project) {
            foreach ($project->contracts()->pluck('contract_sum') as $sum) {
                $contractValue = Money::sum($contractValue, (string) $sum);
            }
        }

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'gross_profit' => $grossProfit,
            // Null, not zero: "we worked for nothing" and "nothing has been
            // billed yet" are different statements, and only one is true here.
            'margin_percent' => Money::isZero($revenue)
                ? null
                : bcdiv(bcmul($grossProfit, '100', Money::WORKING_SCALE), $revenue, 2),
            'active_projects' => $projects->where('status', ProjectStatus::Active)->count(),
            'contract_value' => $contractValue,
        ];
    }

    /**
     * What is owed to the company, what it owes, and what is being held back.
     *
     * @return array{receivable: string, payable: string, retention_held: string, net: string}
     */
    public function cashPosition(): array
    {
        $projects = Project::query()->get();

        $receivable = '0.0000';

        foreach ($this->receivableAgeing() as $amount) {
            $receivable = Money::sum($receivable, $amount);
        }

        $payable = '0.0000';
        $retentionHeld = '0.0000';

        foreach ($projects as $project) {
            $payable = Money::sum($payable, $this->payables->approvedUnpaidFor($project));
            $retentionHeld = Money::sum($retentionHeld, $this->retention->balanceFor($project));
        }

        return [
            'receivable' => $receivable,
            'payable' => $payable,
            // Held by the client against our invoices — owed to us, but not yet
            // collectable, so it is shown beside the net rather than inside it.
            'retention_held' => $retentionHeld,
            'net' => bcsub($receivable, $payable, Money::SCALE),
        ];
    }

    /**
     * Receivables by age, **oldest bucket first**.
     *
     * The order is the point: an ageing report read newest-first buries the
     * ninety-day debt under the current one, and the ninety-day debt is the
     * reason anybody opens it.
     *
     * @return array<string, string>
     */
    public function receivableAgeing(): array
    {
        $summary = $this->ageing->summaryFor();

        $ordered = [];

        foreach ([
            AgingBucket::OverNinety,
            AgingBucket::SixtyOneToNinety,
            AgingBucket::ThirtyOneToSixty,
            AgingBucket::OneToThirty,
            AgingBucket::Current,
        ] as $bucket) {
            $ordered[$bucket->value] = $summary[$bucket->value] ?? '0.0000';
        }

        return $ordered;
    }

    /**
     * What an executive should see without opening a screen.
     *
     * Only things somebody has to DO something about. A dashboard that lists
     * everything teaches people to ignore it, so an empty list here is the
     * normal, healthy state and is returned as an empty array rather than a row
     * saying "nothing".
     *
     * @return array<int, array{key: string, label: string, count: int, severity: string}>
     */
    public function needsAttention(): array
    {
        $items = [];

        $projectIds = Project::query()->pluck('id')->all();

        // F13: aged past ninety days, and nobody has acknowledged it.
        $overNinety = SalesInvoice::query()
            ->whereIn('project_id', $projectIds)
            ->get()
            ->filter(fn (SalesInvoice $invoice): bool => ! Money::isZero($this->ageing->outstandingFor($invoice))
                && $this->ageing->daysOutstanding($invoice) > 90)
            ->count();

        if ($overNinety > 0) {
            $items[] = [
                'key' => 'ar_over_90',
                'label' => 'Receivables more than 90 days old',
                'count' => $overNinety,
                'severity' => 'danger',
            ];
        }

        $escalations = ArEscalation::query()
            ->whereNull('acknowledged_at')
            ->whereIn('project_id', $projectIds)
            ->count();

        if ($escalations > 0) {
            $items[] = [
                'key' => 'ar_escalations',
                'label' => 'AR escalations nobody has acknowledged',
                'count' => $escalations,
                'severity' => 'warning',
            ];
        }

        // An unexplained variance blocks the period close, so it is work with a
        // deadline attached rather than a number to note.
        $unexplained = OpexVarianceExplanation::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('explained_at')
            ->count();

        if ($unexplained > 0) {
            $items[] = [
                'key' => 'opex_variances',
                'label' => 'Budget variances blocking a period close',
                'count' => $unexplained,
                'severity' => 'warning',
            ];
        }

        return $items;
    }

    /**
     * The S-curve: cumulative revenue and cost, month by month.
     *
     * Cumulative is what makes it a curve rather than a bar chart, and a
     * cumulative line never falls — a month with no postings holds the level of
     * the month before it rather than dropping to zero.
     *
     * @return array{labels: array<int, string>, revenue: array<int, string>, cost: array<int, string>}
     */
    public function cumulativeCurve(int $months = 12): array
    {
        $projectIds = Project::query()->pluck('id')->all();
        $end = CarbonImmutable::parse(Carbon::now())->endOfMonth();

        $labels = [];
        $revenue = [];
        $cost = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthEnd = $end->subMonthsNoOverflow($i)->endOfMonth();

            $labels[] = $monthEnd->format('M Y');
            $revenue[] = $this->ledgerTotal($projectIds, [LedgerCategory::Revenue], $monthEnd);
            $cost[] = $this->ledgerTotal($projectIds, self::COST_CATEGORIES, $monthEnd);
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'cost' => $cost];
    }

    /**
     * Budget against actual cost, per project.
     *
     * Projects with no budget are left out rather than shown at zero: a project
     * budgeted at nothing and a project nobody has budgeted yet would look
     * identical, and only one of them is a problem.
     *
     * @return array<int, array{project: string, budget: string, actual: string, variance: string}>
     */
    public function budgetVersusActual(): array
    {
        $rows = [];

        foreach (Project::query()->orderBy('code')->get() as $project) {
            $budget = '0.0000';

            $amounts = BudgetLine::query()
                ->whereIn('budget_id', $project->budgets()->pluck('id'))
                ->pluck('amount');

            foreach ($amounts as $amount) {
                $budget = Money::sum($budget, (string) $amount);
            }

            if (Money::isZero($budget)) {
                continue;
            }

            $actual = $this->ledgerTotal([$project->getKey()], self::COST_CATEGORIES);

            $rows[] = [
                'project' => $project->code,
                'budget' => $budget,
                'actual' => $actual,
                'variance' => bcsub($budget, $actual, Money::SCALE),
            ];
        }

        return $rows;
    }

    /**
     * Sum the ledger in bcmath, optionally up to a date.
     *
     * @param  array<int, int>  $projectIds
     * @param  array<int, LedgerCategory>  $categories
     */
    private function ledgerTotal(array $projectIds, array $categories, ?CarbonImmutable $upTo = null): string
    {
        if ($projectIds === []) {
            return '0.0000';
        }

        $amounts = ProjectCostLedgerEntry::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('category', array_map(fn (LedgerCategory $c): string => $c->value, $categories))
            ->when($upTo !== null, fn ($query) => $query->whereDate('document_date', '<=', $upTo))
            ->pluck('amount');

        $total = '0.0000';

        foreach ($amounts as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }
}
