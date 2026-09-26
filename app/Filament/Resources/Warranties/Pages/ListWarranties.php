<?php

namespace App\Filament\Resources\Warranties\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Warranties\WarrantiesResource;
use Filament\Resources\Pages\ListRecords;

class ListWarranties extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = WarrantiesResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
