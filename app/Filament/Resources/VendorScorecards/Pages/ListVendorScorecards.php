<?php

namespace App\Filament\Resources\VendorScorecards\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\VendorScorecards\VendorScorecardResource;
use Filament\Resources\Pages\ListRecords;

class ListVendorScorecards extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = VendorScorecardResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
