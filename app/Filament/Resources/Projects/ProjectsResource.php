<?php

namespace App\Filament\Resources\Projects;

use App\Domain\Projects\ProjectAccessService;
use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Projects — opening one, and keeping its details, phase and hold current.
 *
 * Until this existed a project could only be made by a seeder. Every save goes
 * through ProjectService, so the screen refuses what the service refuses:
 * post-construction and closed are not offered (the completion certificate and
 * close-out own them), phase moves forward one step at a time, and the code is
 * fixed once opened.
 *
 * **Only a role that sees every project may open one.** Project assignment
 * (P6-01) hides every project a user is not on, so a project manager who opened
 * a job would lose sight of it the moment they saved. Opening is finance's and
 * the managing director's; a project manager edits the projects they are on.
 */
class ProjectsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Project::class;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $navigationLabel = 'Projects';

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return static::canViewAny()
            && $user instanceof User
            && app(ProjectAccessService::class)->seesEveryProject($user);
    }

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
