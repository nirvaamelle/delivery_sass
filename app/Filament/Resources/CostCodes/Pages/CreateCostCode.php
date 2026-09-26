<?php

namespace App\Filament\Resources\CostCodes\Pages;

use App\Domain\Budgets\CostCodeService;
use App\Domain\Budgets\InvalidBudgetDetail;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\CostCodes\CostCodesResource;
use App\Models\Organization;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCostCode extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = CostCodesResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $organization = Organization::query()->findOrFail($data['organization_id'] ?? null);
        unset($data['organization_id']);

        $user = auth()->user();

        try {
            return app(CostCodeService::class)->add($organization, $data, $user instanceof User ? $user : null);
        } catch (InvalidBudgetDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        }
    }
}
