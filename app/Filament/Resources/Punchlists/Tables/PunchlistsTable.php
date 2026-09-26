<?php

namespace App\Filament\Resources\Punchlists\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Defects outstanding at substantial completion.
 *
 * **"Open" is the honest word for an empty list.** P5-01's rule was that a
 * punchlist nobody walked has no open items either, so the status here reads
 * `closed_at`, never a count of rows. Showing "0 outstanding" on a list nobody
 * has been round would send a client to a site that was never inspected.
 */
class PunchlistsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_on', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('issued_on')->label('Issued')->date()->sortable(),

                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items'),

                // Counted, not stored. There is no status column on the record
                // and there is none here either.
                TextColumn::make('open_items')
                    ->label('Outstanding')
                    ->state(fn ($record): int => $record->items()->whereNull('cleared_at')->count()),

                TextColumn::make('closed_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn ($record): string => $record->closed_at === null ? 'Open' : 'Closed')
                    ->color(fn (string $state): string => $state === 'Closed' ? 'success' : 'warning'),

                TextColumn::make('closedBy.name')->label('Closed by')->placeholder('—'),
                TextColumn::make('closure_note')->label('Closed because')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Filter::make('open')
                    ->label('Still open')
                    ->query(fn (Builder $query): Builder => $query->whereNull('closed_at')),
            ]);
    }
}
