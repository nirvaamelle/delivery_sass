<?php

use App\Domain\Projects\ProjectPhase;
use App\Filament\Resources\Projects\ProjectsResource;

/*
|--------------------------------------------------------------------------
| The pivot, at the surface — spec §1 and §2 (D3)
|--------------------------------------------------------------------------
|
| The business is logistics; the schema is still construction's. The seam is
| deliberate (spec D3), so these tests pin BOTH halves: the vocabulary the user
| reads, and the stored values that must not move under it.
|
*/

it('labels each project phase in client-account language', function () {
    expect(ProjectPhase::ProjectAcquisition->getLabel())->toBe('Onboarding')
        ->and(ProjectPhase::PreConstruction->getLabel())->toBe('Go-Live')
        ->and(ProjectPhase::Construction->getLabel())->toBe('Operating')
        ->and(ProjectPhase::PostConstruction->getLabel())->toBe('Exit');
});

it('leaves the stored phase values untouched so no migration is needed', function () {
    expect(ProjectPhase::ProjectAcquisition->value)->toBe('project_acquisition')
        ->and(ProjectPhase::PreConstruction->value)->toBe('pre_construction')
        ->and(ProjectPhase::Construction->value)->toBe('construction')
        ->and(ProjectPhase::PostConstruction->value)->toBe('post_construction');
});

it('presents projects as client accounts', function () {
    expect(ProjectsResource::getModelLabel())->toBe('client account')
        ->and(ProjectsResource::getPluralModelLabel())->toBe('client accounts');
});

it('takes the panel brand from the application name', function () {
    expect(config('app.name'))->toBe('Logistics');
});
