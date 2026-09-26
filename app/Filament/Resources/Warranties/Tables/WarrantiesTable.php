<?php

namespace App\Filament\Resources\Warranties\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Certificates on file, and when each stops covering anything.
 *
 * **The expiry column is the working content.** A warranty is only useful while
 * it is in force, and the whole point of the register is answering "was this
 * covered when it failed" — so the date it runs out is on the row, and a lapsed
 * certificate says so rather than looking like any other.
 */
class WarrantiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('ends_on', 'asc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('certificate_reference')->label('Certificate')->searchable(),
                TextColumn::make('vendor.code')->label('Vendor')->searchable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('scope')->limit(36),

                TextColumn::make('starts_on')->label('From')->date()->toggleable(),
                TextColumn::make('ends_on')->label('Until')->date()->sortable(),

                TextColumn::make('in_force')
                    ->label('Status')
                    ->badge()
                    ->state(fn ($record): string => $record->ends_on->isBefore(now()->startOfDay()) ? 'Lapsed' : 'In force')
                    ->color(fn (string $state): string => $state === 'In force' ? 'success' : 'gray'),

                TextColumn::make('claims_count')->label('Claims')->counts('claims')->toggleable(),
            ])
            ->filters([
                Filter::make('in_force')
                    ->label('Still in force')
                    ->query(fn (Builder $query): Builder => $query->whereDate('ends_on', '>=', now()->toDateString())),
            ]);
    }
}
