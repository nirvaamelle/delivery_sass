<?php

namespace App\Filament\Resources\CostCodes\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\CostCodes\CostCodesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCostCodes extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = CostCodesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add cost code'),
        ];
    }
}
