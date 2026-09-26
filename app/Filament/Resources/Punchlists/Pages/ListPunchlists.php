<?php

namespace App\Filament\Resources\Punchlists\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use Filament\Resources\Pages\ListRecords;

class ListPunchlists extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = PunchlistsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
