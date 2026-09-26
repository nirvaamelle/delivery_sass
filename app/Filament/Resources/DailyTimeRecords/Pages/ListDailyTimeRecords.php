<?php

namespace App\Filament\Resources\DailyTimeRecords\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\DailyTimeRecords\DailyTimeRecordsResource;
use Filament\Resources\Pages\ListRecords;

class ListDailyTimeRecords extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = DailyTimeRecordsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
