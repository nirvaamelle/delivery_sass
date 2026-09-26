<?php

use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectRole;
use App\Domain\Projects\ProjectStatus;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\ProjectsResource;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Projects on screen
|--------------------------------------------------------------------------
|
| A project could only be made by a seeder. The screen saves through
| ProjectService, so it refuses exactly what the service refuses — and it adds
| one rule of its own: only a role that sees every project may open one, because
| a project manager who opened a job would lose sight of it on save (P6-01).
|
*/

function screenProject(array $overrides = []): Project
{
    return Project::factory()->create(array_merge([
        'phase' => ProjectPhase::ProjectAcquisition,
        'status' => ProjectStatus::Active,
    ], $overrides));
}

function projectManagerOn(Project $project): User
{
    $user = userWithRole('project-manager');
    app(ProjectAccessService::class)->assign($user, $project, ProjectRole::ProjectManager);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Opening
|--------------------------------------------------------------------------
*/

it('lets finance open the open-project page', function () {
    actingAs(userWithRole('finance-manager'));

    get(ProjectsResource::getUrl('create'))->assertSuccessful();
});

it('refuses a project manager the open-project page', function () {
    actingAs(userWithRole('project-manager'));

    expect(ProjectsResource::canCreate())->toBeFalse();
    get(ProjectsResource::getUrl('create'))->assertForbidden();
});

it('opens a project from the form at acquisition, active', function () {
    actingAs(userWithRole('finance-manager'));

    Livewire::test(CreateProject::class)
        ->fillForm([
            'organization_id' => Organization::factory()->create()->getKey(),
            'code' => 'PRJ-FORM-001',
            'name' => 'Riverside Warehouse',
            'client_name' => 'Riverside Logistics Inc.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $project = Project::query()->where('code', 'PRJ-FORM-001')->sole();

    expect($project->phase)->toBe(ProjectPhase::ProjectAcquisition)
        ->and($project->status)->toBe(ProjectStatus::Active);
});

it('shows a duplicate code beside the code field', function () {
    actingAs(userWithRole('finance-manager'));
    $existing = screenProject();

    Livewire::test(CreateProject::class)
        ->fillForm([
            'organization_id' => $existing->organization_id,
            'code' => $existing->code,
            'name' => 'Another',
            'client_name' => 'Another client',
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

it('lets a project manager edit a project they are on, with the code locked', function () {
    $project = screenProject();
    actingAs(projectManagerOn($project));

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertFormFieldDisabled('code')
        ->fillForm(['name' => 'Riverside Warehouse — Phase 2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($project->fresh()->name)->toBe('Riverside Warehouse — Phase 2');
});

it('does not show a project manager a project they are not on', function () {
    $mine = screenProject();
    $theirs = screenProject();
    actingAs(projectManagerOn($mine));

    get(ProjectsResource::getUrl('edit', ['record' => $theirs]))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Phase and hold
|--------------------------------------------------------------------------
*/

it('moves a project to the next phase', function () {
    actingAs(userWithRole('finance-manager'));
    $project = screenProject();

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->callAction('advancePhase');

    expect($project->fresh()->phase)->toBe(ProjectPhase::PreConstruction);
});

it('never offers post-construction by hand', function () {
    actingAs(userWithRole('finance-manager'));
    $project = screenProject(['phase' => ProjectPhase::Construction]);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertActionHidden('advancePhase');
});

it('needs a reason to put a project on hold, and resumes it', function () {
    actingAs(userWithRole('finance-manager'));
    $project = screenProject();

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->callAction('putOnHold', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->callAction('putOnHold', data: ['reason' => 'Client suspended works.']);

    expect($project->fresh()->status)->toBe(ProjectStatus::OnHold);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->callAction('resume');

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

it('offers nothing on a closed project', function () {
    actingAs(userWithRole('finance-manager'));
    $project = screenProject(['status' => ProjectStatus::Closed, 'phase' => ProjectPhase::PostConstruction]);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertActionHidden('advancePhase')
        ->assertActionHidden('putOnHold')
        ->assertActionHidden('resume');
});
