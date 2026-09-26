<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Projects\ProjectsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = ProjectsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Open project'),
        ];
    }
}
