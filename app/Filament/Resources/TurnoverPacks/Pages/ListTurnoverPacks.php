<?php

namespace App\Filament\Resources\TurnoverPacks\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\TurnoverPacks\TurnoverPacksResource;
use Filament\Resources\Pages\ListRecords;

class ListTurnoverPacks extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = TurnoverPacksResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
