<?php

use App\Domain\Equipment\DepreciationMethod;
use App\Domain\Equipment\DepreciationService;
use App\Domain\Equipment\EquipmentRegisterService;
use App\Domain\Equipment\EquipmentStatus;
use App\Domain\Equipment\Ownership;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Equipment\Pages\EditEquipment;
use App\Models\Equipment;
use App\Models\Organization;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Equipment on screen
|--------------------------------------------------------------------------
|
| The register was read-only. A machine can now be added and corrected through
| EquipmentRegisterService; deployment and release stay the row actions they
| were, status is never a field, and the acquisition figures lock once a
| depreciation schedule exists.
|
*/

function screenMachine(): Equipment
{
    return app(EquipmentRegisterService::class)->register(Organization::factory()->create(), [
        'code' => 'EQ-S-'.uniqid(),
        'description' => 'Concrete mixer',
        'ownership' => Ownership::Owned->value,
        'acquisition_cost' => '240000.00',
        'salvage_value' => '24000.00',
        'acquired_on' => '2026-01-01',
        'useful_life_months' => '36',
        'depreciation_method' => DepreciationMethod::StraightLine->value,
    ]);
}

it('lets the storekeeper open the add-equipment page', function () {
    actingAs(userWithRole('storekeeper'));

    get(EquipmentResource::getUrl('create'))->assertSuccessful();
});

it('registers a machine from the form as available', function () {
    actingAs(userWithRole('procurement-head'));

    Livewire::test(CreateEquipment::class)
        ->fillForm([
            'organization_id' => Organization::factory()->create()->getKey(),
            'code' => 'EQ-FORM-01',
            'description' => 'Plate compactor',
            'ownership' => Ownership::Rented->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Equipment::query()->where('code', 'EQ-FORM-01')->sole()->status)->toBe(EquipmentStatus::Available);
});

it('shows salvage above cost beside the salvage field', function () {
    actingAs(userWithRole('procurement-head'));

    Livewire::test(CreateEquipment::class)
        ->fillForm([
            'organization_id' => Organization::factory()->create()->getKey(),
            'code' => 'EQ-FORM-02',
            'description' => 'Generator',
            'ownership' => Ownership::Owned->value,
            'acquisition_cost' => '100.00',
            'salvage_value' => '200.00',
        ])
        ->call('create')
        ->assertHasFormErrors(['salvage_value']);
});

it('edits a machine with the code locked', function () {
    actingAs(userWithRole('procurement-head'));
    $machine = screenMachine();

    Livewire::test(EditEquipment::class, ['record' => $machine->getRouteKey()])
        ->assertFormFieldDisabled('code')
        ->fillForm(['description' => 'Concrete mixer, 1-bagger'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($machine->fresh()->description)->toBe('Concrete mixer, 1-bagger');
});

it('locks the acquisition fields on screen once a schedule exists', function () {
    actingAs(userWithRole('procurement-head'));
    $machine = screenMachine();
    app(DepreciationService::class)->generate($machine->fresh());

    Livewire::test(EditEquipment::class, ['record' => $machine->getRouteKey()])
        ->assertFormFieldDisabled('acquisition_cost')
        ->assertFormFieldDisabled('useful_life_months')
        ->fillForm(['serial_number' => 'SN-1'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($machine->fresh()->serial_number)->toBe('SN-1')
        ->and((string) $machine->fresh()->acquisition_cost)->toBe('240000.0000');
});

it('still refuses a foreman the equipment screen', function () {
    actingAs(userWithRole('foreman'));

    get(EquipmentResource::getUrl('create'))->assertForbidden();
});
