<?php

use App\Filament\Resources\Projects\ProjectsResource;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Filament\Resources\Warranties\WarrantiesResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Hidden, not deleted — spec §8
|--------------------------------------------------------------------------
|
| The construction modules keep their code, their tables and their tests. They
| lose the menu. The third test is the important one: hiding is a PRESENTATION
| decision and must never masquerade as an access control, because a reader who
| believes a hidden screen is a secured screen will stop securing it.
|
*/

it('hides a retired construction module from the navigation', function () {
    expect(PunchlistsResource::shouldRegisterNavigation())->toBeFalse()
        ->and(WarrantiesResource::shouldRegisterNavigation())->toBeFalse();
});

it('keeps a retained module in the navigation', function () {
    expect(ProjectsResource::shouldRegisterNavigation())->toBeTrue();
});

it('still answers a retired module url, because hiding is not access control', function () {
    actingAs(panelUser());

    get(PunchlistsResource::getUrl('index'))->assertSuccessful();
});

it('names every retired module as a real resource class', function () {
    foreach (config('modules.retired') as $resource) {
        expect(class_exists($resource))->toBeTrue("{$resource} does not exist");
    }
});
