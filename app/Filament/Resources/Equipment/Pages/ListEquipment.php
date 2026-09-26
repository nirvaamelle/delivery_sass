<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Equipment\EquipmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEquipment extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Register equipment'),
        ];
    }
}
