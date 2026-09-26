<?php

namespace App\Filament\Resources\RetentionReleases\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What has been claimed back, and what has actually arrived.
 *
 * **The status column is the distinction the whole task turns on.** A claim
 * raised is a request; the client is still holding the money until they pay it.
 * A screen showing "released" for both would have somebody close a project on a
 * promise.
 */
class RetentionReleasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('claimed_on', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('contract.number')->label('Contract')->searchable()->toggleable(),

                TextColumn::make('amount')->money('PHP')->sortable(),

                TextColumn::make('claimed_on')->label('Claimed')->date()->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn ($record): string => $record->collected_on === null ? 'Uncollected' : 'Collected')
                    ->color(fn (string $state): string => $state === 'Collected' ? 'success' : 'warning'),

                TextColumn::make('collection_reference')->label('Receipt')->placeholder('-'),
                TextColumn::make('collected_on')->label('Received')->date()->placeholder('-')->toggleable(),
            ])
            ->filters([
                Filter::make('uncollected')
                    ->label('Claimed, not yet paid')
                    ->query(fn (Builder $query): Builder => $query->whereNull('collected_on')),
            ]);
    }
}
