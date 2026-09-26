<?php

namespace App\Domain\Opex;

use App\Domain\Support\Money;
use App\Models\BudgetLine;
use App\Models\CostCode;
use App\Models\OpexPeriod;
use App\Models\OpexVarianceExplanation;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Budget versus actual, and slide 8's day-30 variance gate — P4-05 and P4-06.
 *
 * "Variance above 10% explained in writing." Two words carry it, the same two
 * that carried F10 on the payroll side.
 *
 * **Above 10%**, so ten exactly is inside the threshold. A gate that fires on
 * its own boundary blocks a close that met the standard, and the person asked to
 * explain it has nothing to say beyond "it is exactly ten".
 *
 * **Explained**, meaning a written reason per cost code, recorded against the
 * period before the close proceeds. Not acknowledged, not ticked. And the gate
 * BLOCKS: a month-end review that can be skipped is skipped in the months with
 * the variances, which are the months worth reviewing.
 *
 * An **underspend** blocks as well as an overspend. A cost code 40% under budget
 * is as interesting as one 40% over — either the work is not being done or the
 * budget was wrong, and both need a sentence.
 *
 * PLACEHOLDER: the threshold is `opex.variance.threshold_percent`, defaulting to
 * slide 8's 10%.
 */
class BudgetActualService
{
    public function __construct(
        private readonly ExpenseService $expenses,
    ) {}

    /**
     * Budget against actual for every budgeted cost code on a project.
     *
     * @return array<int, array{cost_code_id: int, budget: string, actual: string, variance: string, variance_percent: string, requires_explanation: bool}>
     */
    public function forPeriod(Project $project, int $year, int $month): array
    {
        $threshold = (string) config('opex.variance.threshold_percent', '10.00');

        $lines = BudgetLine::query()
            ->whereIn('budget_id', $project->budgets()->pluck('id'))
            ->get();

        $rows = [];

        foreach ($lines as $line) {
            $costCode = $line->costCode()->sole();

            $budget = (string) $line->amount;
            $actual = $this->expenses->totalFor($project, $year, $month, $costCode);
            $variance = bcsub($actual, $budget, Money::SCALE);

            $percent = $this->variancePercent($budget, $variance);

            $rows[] = [
                'cost_code_id' => (int) $costCode->getKey(),
                'budget' => $budget,
                'actual' => $actual,
                'variance' => $variance,
                'variance_percent' => $percent,
                // Strictly ABOVE the threshold, and in either direction.
                'requires_explanation' => bccomp(ltrim($percent, '-'), $threshold, 2) > 0,
            ];
        }

        return $rows;
    }

    /**
     * Cost codes whose variance needs explaining and has none.
     *
     * @return array<int, int>
     */
    public function unexplained(OpexPeriod $period): array
    {
        $open = [];

        foreach ($this->projectsFor($period) as $project) {
            $explained = OpexVarianceExplanation::query()
                ->where('opex_period_id', $period->getKey())
                ->where('project_id', $project->getKey())
                ->pluck('cost_code_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            foreach ($this->forPeriod($project, $period->period_year, $period->period_month) as $row) {
                if ($row['requires_explanation'] && ! in_array($row['cost_code_id'], $explained, true)) {
                    $open[] = $row['cost_code_id'];
                }
            }
        }

        sort($open);

        return array_values(array_unique($open));
    }

    /**
     * Record why a cost code missed its budget.
     *
     * @throws DomainException when the period is past review, the explanation is
     *                         blank, or the variance is inside the threshold
     */
    public function explain(OpexPeriod $period, CostCode $costCode, string $explanation, User $by): OpexVarianceExplanation
    {
        if ($period->stage->sequence() > OpexStage::BudgetReview->sequence()) {
            // Explanations belong to the review. Written after the close they
            // justify a decision rather than inform it — the same rule F10's
            // payroll explanations follow.
            throw new DomainException(sprintf(
                'Period %d-%02d is at %s. Variance explanations are written during the budget review, before the close.',
                $period->period_year,
                $period->period_month,
                $period->stage->value,
            ));
        }

        if (trim($explanation) === '') {
            throw new DomainException('Slide 8 requires the variance EXPLAINED in writing. A blank explanation is an acknowledgement.');
        }

        $project = $this->projectFor($period, $costCode);

        $row = collect($this->forPeriod($project, $period->period_year, $period->period_month))
            ->firstWhere('cost_code_id', (int) $costCode->getKey());

        if ($row === null || ! $row['requires_explanation']) {
            throw new DomainException(sprintf(
                'Cost code %s has no variance above the threshold in %d-%02d.',
                $costCode->code,
                $period->period_year,
                $period->period_month,
            ));
        }

        return OpexVarianceExplanation::query()->create([
            'opex_period_id' => $period->getKey(),
            'project_id' => $project->getKey(),
            'cost_code_id' => $costCode->getKey(),
            'budget_amount' => $row['budget'],
            'actual_amount' => $row['actual'],
            'variance_amount' => $row['variance'],
            'variance_percent' => $row['variance_percent'],
            'explanation' => $explanation,
            'explained_at' => now(),
            'explained_by_user_id' => $by->getKey(),
        ]);
    }

    /**
     * Variance as a share of budget.
     *
     * A budget of effectively nothing cannot be divided into, so the variance is
     * reported against one centavo rather than returning null — a cost code that
     * was spent against after its budget was cut to zero is the most interesting
     * row on the report, and it must not read as "no variance".
     */
    private function variancePercent(string $budget, string $variance): string
    {
        $divisor = bccomp($budget, '0.0001', Money::SCALE) <= 0 ? '0.0001' : $budget;

        return Money::round(
            bcmul(bcdiv($variance, $divisor, Money::WORKING_SCALE), '100', Money::WORKING_SCALE),
            2,
        );
    }

    /**
     * Projects with a budget in this organization.
     *
     * @return Collection<int, Project>
     */
    private function projectsFor(OpexPeriod $period): Collection
    {
        return Project::query()
            ->where('organization_id', $period->organization_id)
            ->get();
    }

    /**
     * @throws DomainException when no project in the period budgets this code
     */
    private function projectFor(OpexPeriod $period, CostCode $costCode): Project
    {
        foreach ($this->projectsFor($period) as $project) {
            $budgeted = BudgetLine::query()
                ->where('cost_code_id', $costCode->getKey())
                ->whereIn('budget_id', $project->budgets()->pluck('id'))
                ->exists();

            if ($budgeted) {
                return $project;
            }
        }

        throw new DomainException(sprintf(
            'No project in %d-%02d budgets cost code %s.',
            $period->period_year,
            $period->period_month,
            $costCode->code,
        ));
    }
}
