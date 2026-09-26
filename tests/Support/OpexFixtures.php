<?php

/*
|--------------------------------------------------------------------------
| Shared OPEX fixtures
|--------------------------------------------------------------------------
|
| The sixth time this build has moved a helper out of whichever suite declared
| it first, and by now the rule is not in doubt: a function declared in a Pest
| test file is global to every other test file, so the second suite to want the
| name dies with a fatal redeclare rather than a failure.
|
| OPEX makes it unavoidable. A cash advance needs an employee, a project, a
| budget line and a cost code before it can be released at all, and the calendar
| needs every one of those before it can sweep anything — so the same builders
| are wanted by the expense suite, the advance suite, the calendar suite and the
| exit gate.
|
| They go through the real services throughout: a fixture that inserted rows
| would build state the application itself would have refused.
|
*/

use App\Domain\Budgets\BudgetStatus;
use App\Domain\Opex\BudgetActualService;
use App\Domain\Opex\CashAdvanceService;
use App\Domain\Opex\ConsolidationService;
use App\Domain\Opex\CostPerUnitService;
use App\Domain\Opex\ExpenseService;
use App\Domain\Opex\OpexCalendarService;
use App\Domain\Opex\OpexStage;
use App\Domain\Opex\OverheadPoster;
use App\Models\Budget;
use App\Models\CostCode;
use App\Models\Employee;
use App\Models\OpexPeriod;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

function expenses(): ExpenseService
{
    return app(ExpenseService::class);
}

/**
 * A project with an open budget and a cost code that has a budget line.
 *
 * @return array{0: Project, 1: CostCode}
 */
function budgetedProject(string $budgetAmount = '500000.0000'): array
{
    $project = Project::factory()->create();

    $costCode = CostCode::factory()->create([
        'organization_id' => $project->organization_id,
        'code' => '08.10.100',
        'name' => 'Site utilities',
    ]);

    $budget = Budget::factory()->for($project)->create([
        'status' => BudgetStatus::Open,
    ]);

    $budget->lines()->create([
        'cost_code_id' => $costCode->getKey(),
        'amount' => $budgetAmount,
    ]);

    return [$project, $costCode];
}

function advances(): CashAdvanceService
{
    return app(CashAdvanceService::class);
}

/**
 * An employee who can hold an advance, on a budgeted project.
 *
 * @return array{0: Employee, 1: Project, 2: CostCode}
 */
function advanceHolder(): array
{
    [$project, $costCode] = budgetedProject();

    $employee = employees()->hire($project->organization()->sole(), [
        'employee_number' => 'EMP-CA-'.uniqid(),
        'first_name' => 'Site',
        'last_name' => 'Engineer',
        'date_hired' => '2026-01-15',
        'position' => 'Site Engineer',
    ]);

    return [$employee, $project, $costCode];
}

function opexCalendar(): OpexCalendarService
{
    return app(OpexCalendarService::class);
}

function budgetActual(): BudgetActualService
{
    return app(BudgetActualService::class);
}

function overheadPoster(): OverheadPoster
{
    return app(OverheadPoster::class);
}

/**
 * A period at budget review, with `$spent` captured against a ₱100,000 line.
 *
 * @return array{0: Project, 1: CostCode, 2: OpexPeriod}
 */
function periodAtReview(string $spent): array
{
    [$project, $costCode] = budgetedProject('100000.0000');
    $organization = $project->organization()->sole();

    expenses()->capture($project, $costCode, $spent, Carbon::parse('2026-05-10'), 'Site utilities', 'OR-BR-'.uniqid());

    $period = opexCalendar()->open($organization, 2026, 5);

    foreach ([OpexStage::Cutoff, OpexStage::Coding, OpexStage::Validation, OpexStage::Consolidation, OpexStage::BudgetReview] as $stage) {
        $period = opexCalendar()->advanceTo($period->fresh(), $stage, User::factory()->create());
    }

    return [$project, $costCode, $period->fresh()];
}

function consolidation(): ConsolidationService
{
    return app(ConsolidationService::class);
}

/**
 * Cost per unit of verified accomplishment — PLAN.md §7 step 9.
 *
 * Shared rather than declared in CostPerUnitTest: the Phase 6 exit gate walks
 * §7 and calls it too, and a function declared in a test file exists only while
 * that file is loaded.
 */
function costPerUnit(): CostPerUnitService
{
    return app(CostPerUnitService::class);
}
