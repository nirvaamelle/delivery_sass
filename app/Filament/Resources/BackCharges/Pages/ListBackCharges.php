<?php

namespace App\Filament\Resources\BackCharges\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\BackCharges\BackChargesResource;
use Filament\Resources\Pages\ListRecords;

class ListBackCharges extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = BackChargesResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
