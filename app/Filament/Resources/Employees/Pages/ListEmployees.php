<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Employees\EmployeesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = EmployeesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add employee'),
        ];
    }
}
