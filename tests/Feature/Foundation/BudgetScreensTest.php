<?php

use App\Domain\Budgets\BudgetService;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectRole;
use App\Domain\Projects\ProjectStatus;
use App\Filament\Resources\Budgets\BudgetsResource;
use App\Filament\Resources\Budgets\Pages\CreateBudget;
use App\Filament\Resources\Budgets\Pages\EditBudget;
use App\Filament\Resources\Budgets\RelationManagers\LinesRelationManager;
use App\Filament\Resources\CostCodes\CostCodesResource;
use App\Filament\Resources\CostCodes\Pages\CreateCostCode;
use App\Filament\Resources\CostCodes\Pages\EditCostCode;
use App\Models\Budget;
use App\Models\CostCode;
use App\Models\Organization;
use App\Models\Project;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Cost codes and budgets on screen
|--------------------------------------------------------------------------
|
| Both were seeded and nothing else, yet a requisition is refused without an
| open budget. The screens save through CostCodeService and BudgetService, so
| they refuse what those refuse.
|
*/

function screenBudgetProject(): Project
{
    return Project::factory()->create(['status' => ProjectStatus::Active]);
}

/*
|--------------------------------------------------------------------------
| Cost codes
|--------------------------------------------------------------------------
*/

it('adds a cost code from the form', function () {
    actingAs(userWithRole('finance-manager'));
    $organization = Organization::factory()->create();

    Livewire::test(CreateCostCode::class)
        ->fillForm(['organization_id' => $organization->getKey(), 'code' => '03', 'name' => 'Concrete'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CostCode::query()->where('organization_id', $organization->getKey())->where('code', '03')->exists())->toBeTrue();
});

it('shows a duplicate cost code beside the code field', function () {
    actingAs(userWithRole('finance-manager'));
    $existing = CostCode::factory()->create(['parent_id' => null]);

    Livewire::test(CreateCostCode::class)
        ->fillForm(['organization_id' => $existing->organization_id, 'code' => $existing->code, 'name' => 'Again'])
        ->call('create')
        ->assertHasFormErrors(['code']);
});

it('renames a cost code with the code locked', function () {
    actingAs(userWithRole('finance-manager'));
    $costCode = CostCode::factory()->create(['parent_id' => null]);

    Livewire::test(EditCostCode::class, ['record' => $costCode->getRouteKey()])
        ->assertFormFieldDisabled('code')
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($costCode->fresh()->name)->toBe('Renamed');
});

it('refuses a project manager the cost code screen', function () {
    actingAs(userWithRole('project-manager'));

    get(CostCodesResource::getUrl('index'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Budgets
|--------------------------------------------------------------------------
*/

it('drafts a budget from the form and lands on its lines', function () {
    actingAs(userWithRole('finance-manager'));
    $project = screenBudgetProject();

    Livewire::test(CreateBudget::class)
        ->fillForm(['project_id' => $project->getKey(), 'name' => 'Original budget'])
        ->call('create')
        ->assertHasNoFormErrors();

    $budget = Budget::query()->where('project_id', $project->getKey())->sole();

    expect($budget->status)->toBe(BudgetStatus::Draft);
});

it('sets a line on a draft and opens the budget', function () {
    actingAs(userWithRole('finance-manager'));
    $project = screenBudgetProject();
    $budget = app(BudgetService::class)->draft($project, 'Budget');
    $code = CostCode::factory()->create(['organization_id' => $project->organization_id, 'parent_id' => null]);

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $budget, 'pageClass' => EditBudget::class])
        ->callTableAction('setLine', data: ['cost_code_id' => $code->getKey(), 'amount' => '125000.00']);

    expect(app(BudgetService::class)->total($budget))->toBe('125000.0000');

    Livewire::test(EditBudget::class, ['record' => $budget->getRouteKey()])
        ->callAction('openBudget');

    expect($budget->fresh()->status)->toBe(BudgetStatus::Open);
});

it('offers no line changes on an open budget', function () {
    actingAs(userWithRole('finance-manager'));
    $project = screenBudgetProject();
    $budget = app(BudgetService::class)->draft($project, 'Budget');
    app(BudgetService::class)->setLine($budget, CostCode::factory()->create(['organization_id' => $project->organization_id, 'parent_id' => null]), '10.00');
    app(BudgetService::class)->open($budget);

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $budget->fresh(), 'pageClass' => EditBudget::class])
        ->assertTableActionHidden('setLine');

    Livewire::test(EditBudget::class, ['record' => $budget->getRouteKey()])
        ->assertActionHidden('openBudget')
        ->assertActionVisible('closeBudget');
});

it('refuses to open an empty budget with a message, not an error page', function () {
    actingAs(userWithRole('finance-manager'));
    $budget = app(BudgetService::class)->draft(screenBudgetProject(), 'Empty');

    Livewire::test(EditBudget::class, ['record' => $budget->getRouteKey()])
        ->callAction('openBudget')
        ->assertNotified();

    expect($budget->fresh()->status)->toBe(BudgetStatus::Draft);
});

it('shows a project manager only the budgets of projects they are on', function () {
    $mine = screenBudgetProject();
    $theirs = screenBudgetProject();
    $pm = userWithRole('project-manager');
    app(ProjectAccessService::class)->assign($pm, $mine, ProjectRole::ProjectManager);

    $myBudget = app(BudgetService::class)->draft($mine, 'Mine');
    $theirBudget = app(BudgetService::class)->draft($theirs, 'Theirs');

    actingAs($pm);

    get(BudgetsResource::getUrl('edit', ['record' => $myBudget]))->assertSuccessful();
    get(BudgetsResource::getUrl('edit', ['record' => $theirBudget]))->assertNotFound();
});
