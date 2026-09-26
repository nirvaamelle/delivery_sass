<?php

namespace App\Domain\Gates\Preconditions;

use App\Domain\Budgets\BudgetStatus;
use App\Domain\Gates\Precondition;
use App\Models\Project;

/**
 * F14, second half: no spending against a project with no opened budget.
 *
 * PLAN.md §5's first control is "no PR without a cost code and confirmed budget
 * availability". Without an opened budget there is no cost code to charge and
 * no availability to confirm, so the budget check downstream has nothing to
 * resolve against.
 */
class ProjectHasOpenBudget implements Precondition
{
    public function name(): string
    {
        return 'project-has-open-budget';
    }

    public function passes(object $subject): bool
    {
        if (! $subject instanceof Project) {
            return false;
        }

        return $subject->budgets()
            ->where('status', BudgetStatus::Open)
            ->exists();
    }

    public function failureMessage(object $subject): string
    {
        return 'The project has no opened budget.';
    }
}
