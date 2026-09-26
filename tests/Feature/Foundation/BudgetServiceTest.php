<?php

use App\Domain\Budgets\BudgetService;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Budgets\InvalidBudgetDetail;
use App\Domain\Gates\Preconditions\ProjectHasOpenBudget;
use App\Domain\Projects\ProjectStatus;
use App\Models\CostCode;
use App\Models\Organization;
use App\Models\Project;

/*
|--------------------------------------------------------------------------
| Budgets
|--------------------------------------------------------------------------
|
| Budgets were seeded and nothing else, yet a purchase requisition is refused
| without an open one (F14). A budget is drafted line by line, opened as an
| act, and closed as an act; only a draft can change, and a project has one
| open budget at a time because the reports add a project's budgets up.
|
*/

function budgets(): BudgetService
{
    return app(BudgetService::class);
}

function budgetProject(): Project
{
    return Project::factory()->create(['status' => ProjectStatus::Active]);
}

function codeFor(Project $project, ?string $code = null): CostCode
{
    return CostCode::factory()->create([
        'organization_id' => $project->organization_id,
        'parent_id' => null,
        'code' => $code ?? 'CC-'.uniqid(),
    ]);
}

it('drafts a budget, sets lines and opens it, which satisfies the requisition gate', function () {
    $project = budgetProject();
    $budget = budgets()->draft($project, 'Original contract budget');

    budgets()->setLine($budget, codeFor($project), '150000.50');
    budgets()->setLine($budget, codeFor($project), '49999.50');

    expect((new ProjectHasOpenBudget)->passes($project))->toBeFalse();

    budgets()->open($budget);

    expect($budget->fresh()->status)->toBe(BudgetStatus::Open)
        ->and(budgets()->total($budget))->toBe('200000.0000')
        ->and((new ProjectHasOpenBudget)->passes($project))->toBeTrue();
});

it('replaces the amount when a cost code is set twice, rather than adding a second line', function () {
    $project = budgetProject();
    $budget = budgets()->draft($project, 'Budget');
    $code = codeFor($project);

    budgets()->setLine($budget, $code, '100.00');
    budgets()->setLine($budget, $code, '250.00');

    expect($budget->lines()->count())->toBe(1)
        ->and(budgets()->total($budget))->toBe('250.0000');
});

it('refuses a line of zero, a negative or a non-number', function (string $amount) {
    $project = budgetProject();

    expect(fn () => budgets()->setLine(budgets()->draft($project, 'Budget'), codeFor($project), $amount))
        ->toThrow(InvalidBudgetDetail::class, 'above zero');
})->with(['0', '-5.00', 'abc']);

it('refuses a cost code from another company', function () {
    $project = budgetProject();
    $foreign = CostCode::factory()->create(['organization_id' => Organization::factory()->create()->getKey(), 'parent_id' => null]);

    expect(fn () => budgets()->setLine(budgets()->draft($project, 'Budget'), $foreign, '10.00'))
        ->toThrow(InvalidBudgetDetail::class, 'another company');
});

it('refuses to open a budget with no lines', function () {
    expect(fn () => budgets()->open(budgets()->draft(budgetProject(), 'Empty')))
        ->toThrow(DomainException::class, 'no lines');
});

it('refuses a second open budget on the same project', function () {
    $project = budgetProject();
    $first = budgets()->draft($project, 'First');
    budgets()->setLine($first, codeFor($project), '10.00');
    budgets()->open($first);

    $second = budgets()->draft($project, 'Second');
    budgets()->setLine($second, codeFor($project), '10.00');

    expect(fn () => budgets()->open($second))->toThrow(DomainException::class, 'already has an open budget');
});

it('opens a new budget once the old one is closed', function () {
    $project = budgetProject();
    $first = budgets()->draft($project, 'First');
    budgets()->setLine($first, codeFor($project), '10.00');
    budgets()->open($first);
    budgets()->close($first);

    $second = budgets()->draft($project, 'Revised');
    budgets()->setLine($second, codeFor($project), '12.00');

    expect(budgets()->open($second)->status)->toBe(BudgetStatus::Open);
});

it('refuses to change an open or closed budget', function () {
    $project = budgetProject();
    $budget = budgets()->draft($project, 'Budget');
    $code = codeFor($project);
    $line = budgets()->setLine($budget, $code, '10.00');
    budgets()->open($budget);

    expect(fn () => budgets()->setLine($budget->fresh(), $code, '20.00'))->toThrow(DomainException::class, 'only a draft');
    expect(fn () => budgets()->removeLine($line->fresh()))->toThrow(DomainException::class, 'only a draft');
    expect(fn () => budgets()->rename($budget->fresh(), 'Renamed'))->toThrow(DomainException::class, 'only a draft');

    budgets()->close($budget->fresh());

    expect(fn () => budgets()->setLine($budget->fresh(), $code, '20.00'))->toThrow(DomainException::class, 'only a draft');
});

it('removes a line from a draft', function () {
    $project = budgetProject();
    $budget = budgets()->draft($project, 'Budget');
    $line = budgets()->setLine($budget, codeFor($project), '10.00');

    budgets()->removeLine($line);

    expect($budget->lines()->count())->toBe(0);
});

it('refuses a budget on a closed project', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Closed]);

    expect(fn () => budgets()->draft($project, 'Late'))->toThrow(DomainException::class, 'closed');
});
