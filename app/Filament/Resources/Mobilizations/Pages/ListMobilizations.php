<?php

namespace App\Filament\Resources\Mobilizations\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Mobilizations\MobilizationsResource;
use Filament\Resources\Pages\ListRecords;

class ListMobilizations extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = MobilizationsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
