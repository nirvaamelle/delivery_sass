<?php

namespace App\Filament\Resources\StockCards\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\StockCards\StockCardsResource;
use Filament\Resources\Pages\ListRecords;

class ListStockCards extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = StockCardsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
