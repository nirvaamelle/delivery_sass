<?php

namespace App\Filament\Resources\OpexPeriods\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\OpexPeriods\OpexPeriodsResource;
use Filament\Resources\Pages\ListRecords;

class ListOpexPeriods extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = OpexPeriodsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
