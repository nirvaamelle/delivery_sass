<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Vendors\VendorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVendor extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
