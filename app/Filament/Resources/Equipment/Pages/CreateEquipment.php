<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Domain\Equipment\EquipmentRegisterService;
use App\Domain\Equipment\InvalidEquipmentDetail;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Models\Organization;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Register a machine through EquipmentRegisterService, never by writing the row.
 */
class CreateEquipment extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = EquipmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $organization = Organization::query()->findOrFail($data['organization_id'] ?? null);
        unset($data['organization_id']);

        $user = auth()->user();

        try {
            return app(EquipmentRegisterService::class)->register($organization, $data, $user instanceof User ? $user : null);
        } catch (InvalidEquipmentDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        }
    }
}
