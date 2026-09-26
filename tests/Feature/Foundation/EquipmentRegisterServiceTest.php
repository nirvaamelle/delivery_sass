<?php

use App\Domain\Equipment\DepreciationMethod;
use App\Domain\Equipment\DepreciationService;
use App\Domain\Equipment\EquipmentRegisterService;
use App\Domain\Equipment\EquipmentStatus;
use App\Domain\Equipment\InvalidEquipmentDetail;
use App\Domain\Equipment\Ownership;
use App\Models\Equipment;
use App\Models\Organization;

/*
|--------------------------------------------------------------------------
| Registering equipment
|--------------------------------------------------------------------------
|
| The register was read-only and filled by seeders. A machine can now be added
| and corrected — but status still moves only by deployment and release, the
| code is fixed, and the figures a depreciation schedule was computed from lock
| once that schedule exists.
|
*/

function register(): EquipmentRegisterService
{
    return app(EquipmentRegisterService::class);
}

function registeredMachine(array $overrides = []): Equipment
{
    return register()->register(Organization::factory()->create(), array_merge([
        'code' => 'EQ-'.uniqid(),
        'description' => 'Backhoe loader',
        'ownership' => Ownership::Owned->value,
        'acquisition_cost' => '3600000.00',
        'salvage_value' => '600000.00',
        'acquired_on' => '2026-01-01',
        'useful_life_months' => '60',
        'depreciation_method' => DepreciationMethod::StraightLine->value,
    ], $overrides));
}

it('registers a machine as available', function () {
    $machine = registeredMachine(['code' => 'EQ-BH-01']);

    expect($machine->code)->toBe('EQ-BH-01')
        ->and($machine->fresh()->status)->toBe(EquipmentStatus::Available)
        ->and((string) $machine->fresh()->acquisition_cost)->toBe('3600000.0000');
});

it('registers a rented machine with no acquisition figures, taking the register defaults', function () {
    // Found by the screen test: a rented compactor has no cost, life or date to
    // enter, and the blank fields reached the database as nulls on NOT NULL
    // columns. Blank means "not given", so the table's defaults apply.
    $machine = register()->register(Organization::factory()->create(), [
        'code' => 'EQ-RENT-01',
        'description' => 'Plate compactor',
        'ownership' => Ownership::Rented->value,
        'acquisition_cost' => null,
        'salvage_value' => null,
        'acquired_on' => null,
        'useful_life_months' => null,
        'depreciation_method' => null,
    ])->fresh();

    expect((int) $machine->useful_life_months)->toBe(60)
        ->and($machine->depreciation_method)->toBe(DepreciationMethod::StraightLine)
        ->and((string) $machine->acquisition_cost)->toBe('0.0000')
        ->and($machine->acquired_on)->toBeNull();
});

it('refuses a machine without a code, description or ownership', function (string $field) {
    $refusal = null;
    try {
        registeredMachine([$field => '']);
    } catch (InvalidEquipmentDetail $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(InvalidEquipmentDetail::class)
        ->and($refusal?->field)->toBe($field);
})->with(['code', 'description', 'ownership']);

it('refuses a duplicate code', function () {
    $machine = registeredMachine();

    expect(fn () => registeredMachine(['code' => $machine->code]))
        ->toThrow(InvalidEquipmentDetail::class, 'already');
});

it('refuses salvage above cost, a negative cost and a useful life of zero', function (array $overrides, string $field) {
    $refusal = null;
    try {
        registeredMachine($overrides);
    } catch (InvalidEquipmentDetail $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(InvalidEquipmentDetail::class)
        ->and($refusal?->field)->toBe($field);
})->with([
    'salvage above cost' => [['salvage_value' => '4000000.00'], 'salvage_value'],
    'negative cost' => [['acquisition_cost' => '-1'], 'acquisition_cost'],
    'zero life' => [['useful_life_months' => '0'], 'useful_life_months'],
]);

it('corrects a description and acquisition figures before any schedule exists', function () {
    $machine = registeredMachine();

    register()->updateDetails($machine, ['description' => 'Backhoe loader 4x4', 'useful_life_months' => '72']);

    expect($machine->fresh()->description)->toBe('Backhoe loader 4x4')
        ->and((int) $machine->fresh()->useful_life_months)->toBe(72);
});

it('refuses to change the code or status', function (string $field, mixed $value) {
    expect(fn () => register()->updateDetails(registeredMachine(), [$field => $value]))
        ->toThrow(InvalidEquipmentDetail::class, $field);
})->with([
    'code' => ['code', 'EQ-NEW'],
    'status' => ['status', EquipmentStatus::Disposed->value],
]);

it('locks the acquisition figures once a depreciation schedule exists', function () {
    $machine = registeredMachine();
    app(DepreciationService::class)->generate($machine->fresh());

    $refusal = null;
    try {
        register()->updateDetails($machine->fresh(), ['acquisition_cost' => '4000000.00']);
    } catch (InvalidEquipmentDetail $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(InvalidEquipmentDetail::class)
        ->and($refusal?->field)->toBe('acquisition_cost');

    // Details the schedule does not depend on still change.
    register()->updateDetails($machine->fresh(), ['serial_number' => 'SN-778812']);

    expect($machine->fresh()->serial_number)->toBe('SN-778812')
        ->and((string) $machine->fresh()->acquisition_cost)->toBe('3600000.0000');
});

it('does not treat an unchanged acquisition figure as a change', function () {
    // The edit form submits every field; an unchanged cost must not be refused.
    $machine = registeredMachine();
    app(DepreciationService::class)->generate($machine->fresh());

    register()->updateDetails($machine->fresh(), ['acquisition_cost' => '3600000.0000', 'description' => 'Renamed']);

    expect($machine->fresh()->description)->toBe('Renamed');
});
