<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Budgets\BudgetsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBudgets extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = BudgetsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Draft budget'),
        ];
    }
}
