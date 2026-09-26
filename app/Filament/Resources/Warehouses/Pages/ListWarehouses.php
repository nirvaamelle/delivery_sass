<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Warehouses\WarehousesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarehouses extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = WarehousesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add warehouse'),
        ];
    }
}
