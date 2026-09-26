<?php

namespace App\Filament\Resources\RetentionReleases\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\RetentionReleases\RetentionReleasesResource;
use Filament\Resources\Pages\ListRecords;

class ListRetentionReleases extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = RetentionReleasesResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
