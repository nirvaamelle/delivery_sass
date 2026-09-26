<?php

namespace App\Filament\Resources\Ledger\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Ledger\LedgerResource;
use Filament\Resources\Pages\ListRecords;

class ListLedger extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = LedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
