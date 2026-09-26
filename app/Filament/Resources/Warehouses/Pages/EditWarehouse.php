<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Warehouses\WarehousesResource;
use Filament\Resources\Pages\EditRecord;

class EditWarehouse extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = WarehousesResource::class;
}
