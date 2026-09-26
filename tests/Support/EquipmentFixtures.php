<?php

/*
|--------------------------------------------------------------------------
| Shared equipment fixtures
|--------------------------------------------------------------------------
|
| Lifted out of `tests/Feature/Equipment/EquipmentRegisterTest.php` when P5-06
| needed them: demobilization asks the equipment register what is still on site,
| so the close-out suite builds machines and assignments too.
|
| A function declared in a Pest test file is global to the whole run, but it
| only EXISTS once that file has been loaded — so a second suite calling it is
| fine when the full suite runs and fatal when that one file is run on its own.
| The support file is what makes both work.
|
*/

use App\Domain\Equipment\DepreciationMethod;
use App\Domain\Equipment\DepreciationService;
use App\Domain\Equipment\EquipmentService;
use App\Domain\Equipment\Ownership;
use App\Models\Equipment;

function equipment(): EquipmentService
{
    return app(EquipmentService::class);
}

function depreciation(): DepreciationService
{
    return app(DepreciationService::class);
}

/**
 * A company-owned excavator: 1,200,000 less 200,000 salvage over 60 months.
 *
 * @param  array<string, mixed>  $overrides
 */
function ownedExcavator(array $overrides = []): Equipment
{
    return Equipment::factory()->create(array_merge([
        'ownership' => Ownership::Owned,
        'acquisition_cost' => '1200000.0000',
        'salvage_value' => '200000.0000',
        'useful_life_months' => 60,
        'acquired_on' => '2026-01-15',
        'depreciation_method' => DepreciationMethod::StraightLine,
    ], $overrides));
}
