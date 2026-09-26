<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Expenses\ExpensesResource;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = ExpensesResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
