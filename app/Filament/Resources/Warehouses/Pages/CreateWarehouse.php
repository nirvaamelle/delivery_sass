<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Warehouses\WarehousesResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWarehouse extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = WarehousesResource::class;
}
