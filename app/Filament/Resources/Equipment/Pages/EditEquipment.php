<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Domain\Equipment\EquipmentRegisterService;
use App\Domain\Equipment\InvalidEquipmentDetail;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Models\Equipment;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Correct a machine's details. No delete — costs, assignments and the
 * depreciation schedule point at it; leaving the fleet is a status.
 */
class EditEquipment extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = EquipmentResource::class;

    /** The fields the form may send; disabled ones are not submitted at all. */
    private const FIELDS = [
        'description', 'category', 'serial_number', 'ownership',
        'acquisition_cost', 'salvage_value', 'acquired_on', 'useful_life_months', 'depreciation_method',
    ];

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Equipment) {
            throw new LogicException('The equipment edit page was given something other than a machine.');
        }

        $user = auth()->user();

        try {
            return app(EquipmentRegisterService::class)->updateDetails($record, Arr::only($data, self::FIELDS), $user instanceof User ? $user : null);
        } catch (InvalidEquipmentDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        }
    }
}
