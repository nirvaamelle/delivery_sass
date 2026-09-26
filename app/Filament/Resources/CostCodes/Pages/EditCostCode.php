<?php

namespace App\Filament\Resources\CostCodes\Pages;

use App\Domain\Budgets\CostCodeService;
use App\Domain\Budgets\InvalidBudgetDetail;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\CostCodes\CostCodesResource;
use App\Models\CostCode;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Rename a cost code or move it under another parent. No delete.
 */
class EditCostCode extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = CostCodesResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof CostCode) {
            throw new LogicException('The cost code edit page was given something other than a cost code.');
        }

        $user = auth()->user();

        try {
            return app(CostCodeService::class)->update($record, Arr::only($data, ['name', 'parent_id']), $user instanceof User ? $user : null);
        } catch (InvalidBudgetDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        }
    }
}
