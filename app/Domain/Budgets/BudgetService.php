<?php

namespace App\Domain\Budgets;

use App\Domain\Projects\ProjectStatus;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostCode;
use App\Models\Project;
use App\Models\User;
use DomainException;

/**
 * A project budget — drafted line by line, opened as an act, closed as an act.
 *
 * F14 gates a purchase requisition on "an opened budget", and the budget-vs-actual
 * and cost-per-unit reports read budget lines. So:
 *
 * **Only a draft can be changed.** Once open, the lines are the number
 * requisitions and variance reports resolve against; changing them would restate
 * reports already reviewed.
 *
 * **One open budget per project.** The reports add up a project's budgets, so a
 * second open budget would count every line twice.
 *
 * **Opening needs at least one line.** An open budget of nothing passes the
 * requisition gate while budgeting nothing.
 *
 * **One line per cost code, positive amounts, codes from the project's company.**
 */
class BudgetService
{
    public function draft(Project $project, string $name, ?User $by = null): Budget
    {
        if ($project->status === ProjectStatus::Closed) {
            throw new DomainException('The project is closed; it takes no new budget.');
        }

        if (trim($name) === '') {
            throw new InvalidBudgetDetail('name', 'A budget needs a name.');
        }

        $budget = Budget::query()->create([
            'project_id' => $project->getKey(),
            'name' => trim($name),
            'status' => BudgetStatus::Draft,
        ]);

        activity()->performedOn($budget)->causedBy($by)->log('budget-drafted');

        return $budget;
    }

    public function rename(Budget $budget, string $name, ?User $by = null): Budget
    {
        $this->assertDraft($budget);

        if (trim($name) === '') {
            throw new InvalidBudgetDetail('name', 'A budget needs a name.');
        }

        $budget->name = trim($name);
        $budget->save();

        return $budget;
    }

    public function setLine(Budget $budget, CostCode $costCode, string $amount, ?User $by = null): BudgetLine
    {
        $this->assertDraft($budget);

        if (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new InvalidBudgetDetail('amount', 'A budget line needs an amount above zero.');
        }

        $project = $budget->project()->sole();

        if ((int) $costCode->organization_id !== (int) $project->organization_id) {
            throw new InvalidBudgetDetail('cost_code_id', 'That cost code belongs to another company.');
        }

        $line = BudgetLine::query()->updateOrCreate(
            ['budget_id' => $budget->getKey(), 'cost_code_id' => $costCode->getKey()],
            ['amount' => bcadd($amount, '0', 4)],
        );

        activity()->performedOn($budget)->causedBy($by)
            ->withProperties(['cost_code_id' => $costCode->getKey(), 'amount' => (string) $line->amount])
            ->log('budget-line-set');

        return $line;
    }

    public function removeLine(BudgetLine $line, ?User $by = null): void
    {
        $budget = $line->budget()->sole();
        $this->assertDraft($budget);

        $line->delete();

        activity()->performedOn($budget)->causedBy($by)
            ->withProperties(['cost_code_id' => $line->cost_code_id])
            ->log('budget-line-removed');
    }

    public function open(Budget $budget, ?User $by = null): Budget
    {
        $this->assertDraft($budget);

        if (! $budget->lines()->exists()) {
            throw new DomainException('A budget with no lines cannot be opened — it would pass the requisition gate while budgeting nothing.');
        }

        $alreadyOpen = Budget::query()
            ->where('project_id', $budget->project_id)
            ->where('status', BudgetStatus::Open)
            ->exists();

        if ($alreadyOpen) {
            throw new DomainException('This project already has an open budget. Close it first — the reports add budgets up, so two open budgets count every line twice.');
        }

        $budget->status = BudgetStatus::Open;
        $budget->save();

        activity()->performedOn($budget)->causedBy($by)->log('budget-opened');

        return $budget;
    }

    public function close(Budget $budget, ?User $by = null): Budget
    {
        if ($budget->status !== BudgetStatus::Open) {
            throw new DomainException('Only an open budget can be closed.');
        }

        $budget->status = BudgetStatus::Closed;
        $budget->save();

        activity()->performedOn($budget)->causedBy($by)->log('budget-closed');

        return $budget;
    }

    /**
     * The sum of a budget's lines, in bcmath.
     */
    public function total(Budget $budget): string
    {
        $total = '0.0000';

        foreach ($budget->lines()->pluck('amount') as $amount) {
            $total = bcadd($total, (string) $amount, 4);
        }

        return $total;
    }

    private function assertDraft(Budget $budget): void
    {
        if ($budget->status !== BudgetStatus::Draft) {
            throw new DomainException(sprintf('Budget "%s" is %s; only a draft can be changed.', $budget->name, $budget->status->value));
        }
    }
}
