<?php

use App\Models\Organization;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| The facility register — spec §4.1
|--------------------------------------------------------------------------
|
| A register, not a hierarchy. Bins and directed putaway are out of scope
| (spec §10) until a client needs them; what every other logistics table needs
| today is a stable id to hang off.
|
*/

it('registers a warehouse', function () {
    $warehouse = Warehouse::factory()->create([
        'code' => 'WH-MNL-01',
        'name' => 'Manila Distribution Centre',
    ]);

    expect($warehouse->code)->toBe('WH-MNL-01')
        ->and($warehouse->name)->toBe('Manila Distribution Centre')
        ->and($warehouse->is_active)->toBeTrue();
});

it('refuses two warehouses with the same code in one organization', function () {
    $organization = Organization::factory()->create();

    Warehouse::factory()->for($organization)->create(['code' => 'WH-MNL-01']);
    Warehouse::factory()->for($organization)->create(['code' => 'WH-MNL-01']);
})->throws(QueryException::class);

it('lets two organizations each use the same code', function () {
    Warehouse::factory()->for(Organization::factory()->create())->create(['code' => 'WH-01']);
    Warehouse::factory()->for(Organization::factory()->create())->create(['code' => 'WH-01']);

    expect(Warehouse::query()->where('code', 'WH-01')->count())->toBe(2);
});

it('belongs to an organization', function () {
    $warehouse = Warehouse::factory()->create();

    expect($warehouse->organization)->toBeInstanceOf(Organization::class);
});

it('can be deactivated without being deleted', function () {
    $warehouse = Warehouse::factory()->inactive()->create();

    expect($warehouse->is_active)->toBeFalse()
        ->and(Warehouse::query()->count())->toBe(1);
});
