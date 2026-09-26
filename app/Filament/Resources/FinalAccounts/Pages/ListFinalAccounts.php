<?php

namespace App\Filament\Resources\FinalAccounts\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\FinalAccounts\FinalAccountsResource;
use Filament\Resources\Pages\ListRecords;

class ListFinalAccounts extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = FinalAccountsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
