<?php

namespace App\Filament\Resources\FinalAccounts\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What each project made, as certified at close-out.
 *
 * These are the FILED figures, not live ones. The forecast half moves whenever
 * an order is raised, and a screen that recomputed it would show a different
 * final account each time somebody opened it - the opposite of what a filed
 * report is for.
 */
class FinalAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('filed_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('filed_at')->label('Filed')->date()->sortable(),

                TextColumn::make('revenue')->money('PHP')->sortable(),
                TextColumn::make('total_cost')->label('Cost')->money('PHP')->sortable(),
                TextColumn::make('gross_profit')->label('Gross profit')->money('PHP')->sortable(),

                TextColumn::make('margin_percent')
                    ->label('Margin')
                    ->suffix('%')
                    ->badge()
                    ->color(fn ($record): string => bccomp((string) $record->margin_percent, '0', 2) >= 0 ? 'success' : 'danger'),

                TextColumn::make('forecast_final_cost')->label('Forecast cost')->money('PHP')->toggleable(),
                TextColumn::make('committed_cost')->label('Committed')->money('PHP')->toggleable(),
                TextColumn::make('filedBy.name')->label('Filed by')->toggleable(),
            ]);
    }
}
