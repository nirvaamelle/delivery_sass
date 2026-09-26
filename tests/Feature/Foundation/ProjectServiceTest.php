<?php

use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectService;
use App\Domain\Projects\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Opening and maintaining a project
|--------------------------------------------------------------------------
|
| Projects had no domain service: they were created by factories and seeders,
| and nobody could open one without the database. The setup screen needs a door,
| and the door holds the rules the rest of the build already assumes.
|
| **Two fields have owners elsewhere, and this service refuses them.** A project
| reaches POST-CONSTRUCTION only through the substantial completion certificate
| (P5-01), which is what the defects liability period and retention date from.
| It reaches CLOSED only through close-out (P5-07), which refuses while retention
| is uncollected. A dropdown for either would let somebody skip both.
|
| **Phase moves forward one step at a time.** Acquisition, pre-construction,
| construction. Backwards would re-open a stage whose documents are already
| issued; skipping would let construction start with no pre-construction stage
| recorded at all.
|
| **The project code is fixed.** It is snapshotted onto every ledger row and
| printed on every document number's paperwork.
|
*/

function projectService(): ProjectService
{
    return app(ProjectService::class);
}

function openedProject(array $overrides = []): Project
{
    return projectService()->open(Organization::factory()->create(), array_merge([
        'code' => 'PRJ-'.uniqid(),
        'name' => 'Northgate Terminal',
        'client_name' => 'Northgate Port Authority',
    ], $overrides), User::factory()->create());
}

/*
|--------------------------------------------------------------------------
| Opening
|--------------------------------------------------------------------------
*/

it('opens a project at acquisition, active', function () {
    $project = openedProject(['code' => 'PRJ-NEW-1']);

    expect($project->code)->toBe('PRJ-NEW-1')
        ->and($project->phase)->toBe(ProjectPhase::ProjectAcquisition)
        ->and($project->status)->toBe(ProjectStatus::Active);
});

it('refuses to open a project without the fields every document needs', function (string $field) {
    $attributes = ['code' => 'PRJ-REQ-'.uniqid(), 'name' => 'Northgate', 'client_name' => 'Northgate Port Authority'];
    unset($attributes[$field]);

    expect(fn () => projectService()->open(Organization::factory()->create(), $attributes, User::factory()->create()))
        ->toThrow(DomainException::class, $field);
})->with(['code', 'name', 'client_name']);

it('refuses a duplicate project code with a readable message', function () {
    $existing = openedProject();

    expect(fn () => openedProject(['code' => $existing->code]))
        ->toThrow(DomainException::class, 'already');
});

it('refuses a duplicate code even from somebody who cannot see the other project', function () {
    // The uniqueness check reads past the project scope. Otherwise a user
    // assigned to nothing would be told a taken code is free, and then hit the
    // database's unique index instead of a message.
    $existing = openedProject();

    auth()->login(User::factory()->create());

    expect(fn () => openedProject(['code' => $existing->code]))
        ->toThrow(DomainException::class, 'already');
});

it('refuses to open a project straight into post-construction or closed', function (string $field, mixed $value) {
    expect(fn () => openedProject([$field => $value]))
        ->toThrow(DomainException::class, $field);
})->with([
    'post-construction' => ['phase', ProjectPhase::PostConstruction],
    'closed' => ['status', ProjectStatus::Closed],
]);

/*
|--------------------------------------------------------------------------
| Details
|--------------------------------------------------------------------------
*/

it('updates the name and client', function () {
    $project = openedProject();

    $updated = projectService()->updateDetails($project, [
        'name' => 'Northgate Terminal — Phase 2',
        'client_name' => 'Northgate Port Authority (NPA)',
    ], User::factory()->create());

    expect($updated->name)->toBe('Northgate Terminal — Phase 2')
        ->and($updated->client_name)->toBe('Northgate Port Authority (NPA)');
});

it('refuses to change what has an owner elsewhere or is fixed', function (string $field, mixed $value) {
    $project = openedProject();

    expect(fn () => projectService()->updateDetails($project, [$field => $value], User::factory()->create()))
        ->toThrow(DomainException::class, $field);
})->with([
    'code' => ['code', 'PRJ-CHANGED'],
    'company' => ['organization_id', 999],
    'phase' => ['phase', ProjectPhase::Construction],
    'status' => ['status', ProjectStatus::OnHold],
]);

it('refuses a blank name or client on update', function (string $field) {
    $project = openedProject();

    expect(fn () => projectService()->updateDetails($project, [$field => '  '], User::factory()->create()))
        ->toThrow(DomainException::class, $field);
})->with(['name', 'client_name']);

/*
|--------------------------------------------------------------------------
| Phase
|--------------------------------------------------------------------------
*/

it('advances the phase one step at a time', function () {
    $project = openedProject();

    $project = projectService()->advancePhase($project, ProjectPhase::PreConstruction, User::factory()->create());
    $project = projectService()->advancePhase($project, ProjectPhase::Construction, User::factory()->create());

    expect($project->phase)->toBe(ProjectPhase::Construction);
});

it('refuses to skip a phase', function () {
    expect(fn () => projectService()->advancePhase(openedProject(), ProjectPhase::Construction, User::factory()->create()))
        ->toThrow(DomainException::class, 'one phase at a time');
});

it('refuses to move a phase backwards', function () {
    $project = openedProject();
    $project = projectService()->advancePhase($project, ProjectPhase::PreConstruction, User::factory()->create());

    expect(fn () => projectService()->advancePhase($project, ProjectPhase::ProjectAcquisition, User::factory()->create()))
        ->toThrow(DomainException::class, 'backwards');
});

it('refuses post-construction by hand, because the completion certificate owns it', function () {
    $project = openedProject();
    $project = projectService()->advancePhase($project, ProjectPhase::PreConstruction, User::factory()->create());
    $project = projectService()->advancePhase($project, ProjectPhase::Construction, User::factory()->create());

    expect(fn () => projectService()->advancePhase($project, ProjectPhase::PostConstruction, User::factory()->create()))
        ->toThrow(DomainException::class, 'substantial completion');
});

/*
|--------------------------------------------------------------------------
| On hold
|--------------------------------------------------------------------------
*/

it('puts a project on hold with a reason, and resumes it', function () {
    $project = openedProject();

    $held = projectService()->putOnHold($project, 'Client suspended works pending permit.', User::factory()->create());
    expect($held->status)->toBe(ProjectStatus::OnHold);

    $resumed = projectService()->resume($held, User::factory()->create());
    expect($resumed->status)->toBe(ProjectStatus::Active);
});

it('refuses to put a project on hold without a reason', function () {
    expect(fn () => projectService()->putOnHold(openedProject(), '  ', User::factory()->create()))
        ->toThrow(DomainException::class, 'reason');
});

it('refuses to hold or resume a closed project', function () {
    // Closed is close-out's, and final. Re-opening it by resuming would put a
    // project back in the books with its retention already collected.
    $project = openedProject();
    $project->forceFill(['status' => ProjectStatus::Closed])->save();

    expect(fn () => projectService()->putOnHold($project->fresh(), 'Late defect.', User::factory()->create()))
        ->toThrow(DomainException::class, 'closed');

    expect(fn () => projectService()->resume($project->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, 'closed');
});

it('refuses to edit the details of a closed project', function () {
    $project = openedProject();
    $project->forceFill(['status' => ProjectStatus::Closed])->save();

    expect(fn () => projectService()->updateDetails($project->fresh(), ['name' => 'Renamed'], User::factory()->create()))
        ->toThrow(DomainException::class, 'closed');
});
