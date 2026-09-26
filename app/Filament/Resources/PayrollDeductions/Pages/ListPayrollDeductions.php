<?php

namespace App\Filament\Resources\PayrollDeductions\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\PayrollDeductions\PayrollDeductionsResource;
use Filament\Resources\Pages\ListRecords;

class ListPayrollDeductions extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = PayrollDeductionsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
