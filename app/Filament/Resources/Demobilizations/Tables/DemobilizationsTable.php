<?php

namespace App\Filament\Resources\Demobilizations\Tables;

use App\Domain\Closeout\DemobilizationService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Releasing the site, and everything that stops it.
 *
 * The blockers column counts people, plant and money together, because that is
 * what actually holds a demobilization up - reading it off the clearance rows
 * alone would show a site as clear while an excavator sat on it.
 */
class DemobilizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('opened_at')->label('Opened')->date()->sortable(),

                TextColumn::make('clearances_count')->label('People')->counts('clearances'),

                TextColumn::make('uncleared')
                    ->label('Not cleared')
                    ->state(fn ($record): int => $record->clearances()->whereNull('cleared_at')->count()),

                TextColumn::make('blockers')
                    ->label('Blockers')
                    ->state(fn ($record): int => count(app(DemobilizationService::class)->outstandingFor($record))),

                TextColumn::make('completed_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn ($record): string => $record->completed_at === null ? 'Open' : 'Complete')
                    ->color(fn (string $state): string => $state === 'Complete' ? 'success' : 'warning'),
            ])
            ->filters([
                Filter::make('open')
                    ->label('Still open')
                    ->query(fn (Builder $query): Builder => $query->whereNull('completed_at')),
            ]);
    }
}
