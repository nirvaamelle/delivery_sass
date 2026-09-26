<?php

namespace App\Filament\Resources\ArEscalations\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\ArEscalations\ArEscalationsResource;
use Filament\Resources\Pages\ListRecords;

class ListArEscalations extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = ArEscalationsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
