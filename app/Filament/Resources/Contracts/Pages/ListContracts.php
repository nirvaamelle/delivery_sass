<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Contracts\ContractsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContracts extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = ContractsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Draft contract'),
        ];
    }
}
