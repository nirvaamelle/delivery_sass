<?php

namespace App\Filament\Resources\CashAdvances\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\CashAdvances\CashAdvancesResource;
use Filament\Resources\Pages\ListRecords;

class ListCashAdvances extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = CashAdvancesResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
