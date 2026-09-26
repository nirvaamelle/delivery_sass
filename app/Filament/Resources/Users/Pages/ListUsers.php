<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Users\UsersResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = UsersResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add account'),
        ];
    }
}
