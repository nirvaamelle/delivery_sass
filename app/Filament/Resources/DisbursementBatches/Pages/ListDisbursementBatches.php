<?php

namespace App\Filament\Resources\DisbursementBatches\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\DisbursementBatches\DisbursementBatchesResource;
use Filament\Resources\Pages\ListRecords;

class ListDisbursementBatches extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = DisbursementBatchesResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
