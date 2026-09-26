<?php

namespace App\Filament\Resources\Demobilizations\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Demobilizations\DemobilizationsResource;
use Filament\Resources\Pages\ListRecords;

class ListDemobilizations extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = DemobilizationsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
