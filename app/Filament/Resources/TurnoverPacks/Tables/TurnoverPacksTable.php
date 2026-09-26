<?php

namespace App\Filament\Resources\TurnoverPacks\Tables;

use App\Domain\Closeout\TurnoverService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What was handed over, and whether the client has signed for it.
 *
 * The outstanding count is computed rather than stored, and it counts the
 * REGISTER-answered lines too - a pack whose paper documents are all filed can
 * still be short a warranty certificate nobody chased, and a screen reading only
 * `filed_at` would show it as complete.
 */
class TurnoverPacksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('assembled_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('assembled_at')->label('Assembled')->date()->sortable(),

                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->state(fn ($record): int => count(app(TurnoverService::class)->missingFor($record))),

                TextColumn::make('accepted_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn ($record): string => $record->accepted_at === null ? 'Awaiting acceptance' : 'Accepted')
                    ->color(fn (string $state): string => $state === 'Accepted' ? 'success' : 'warning'),

                // The name the client signed under. Not a user id - the person
                // signing for the client holds no account here.
                TextColumn::make('client_representative')->label('Accepted by (client)')->placeholder('-'),
                TextColumn::make('acceptedBy.name')->label('Countersigned')->placeholder('-')->toggleable(),
            ])
            ->filters([
                Filter::make('awaiting')
                    ->label('Awaiting acceptance')
                    ->query(fn (Builder $query): Builder => $query->whereNull('accepted_at')),
            ]);
    }
}
