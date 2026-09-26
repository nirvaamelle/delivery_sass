<?php

namespace App\Filament\Resources\Permits\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Permits\PermitsResource;
use Filament\Resources\Pages\ListRecords;

class ListPermits extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = PermitsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
