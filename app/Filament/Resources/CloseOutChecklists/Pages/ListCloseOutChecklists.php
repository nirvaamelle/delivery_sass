<?php

namespace App\Filament\Resources\CloseOutChecklists\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\CloseOutChecklists\CloseOutChecklistsResource;
use Filament\Resources\Pages\ListRecords;

class ListCloseOutChecklists extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = CloseOutChecklistsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
