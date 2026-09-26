<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Vendors\VendorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVendor extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = VendorResource::class;
}
