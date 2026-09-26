<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Vendors\VendorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendors extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
