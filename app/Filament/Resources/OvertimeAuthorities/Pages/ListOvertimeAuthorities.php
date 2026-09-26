<?php

namespace App\Filament\Resources\OvertimeAuthorities\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\OvertimeAuthorities\OvertimeAuthoritiesResource;
use Filament\Resources\Pages\ListRecords;

class ListOvertimeAuthorities extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = OvertimeAuthoritiesResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
