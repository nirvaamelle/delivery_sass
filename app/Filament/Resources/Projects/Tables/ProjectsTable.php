<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectStatus;
use App\Filament\Resources\Projects\ProjectsResource;
use App\Models\Project;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The project register. Rows are already limited to the projects the user is
 * assigned to (ProjectScope), so this needs no filtering of its own for that.
 */
class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->recordUrl(fn (Project $record): string => ProjectsResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('client_name')->label('Client')->searchable()->toggleable(),

                TextColumn::make('phase')
                    ->badge()
                    ->formatStateUsing(fn (ProjectPhase $state): string => self::label($state->value)),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ProjectStatus $state): string => self::label($state->value))
                    ->color(fn (ProjectStatus $state): string => match ($state) {
                        ProjectStatus::Active => 'success',
                        ProjectStatus::OnHold => 'warning',
                        ProjectStatus::Closed => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('phase')->options(self::options(ProjectPhase::cases())),
                SelectFilter::make('status')->options(self::options(ProjectStatus::cases())),
            ]);
    }

    public static function label(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }

    /**
     * @param  array<int, ProjectPhase|ProjectStatus>  $cases
     * @return array<string, string>
     */
    private static function options(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn (ProjectPhase|ProjectStatus $case): array => [$case->value => self::label($case->value)])->all();
    }
}
