<?php

namespace App\Filament\Resources\Budgets\Tables;

use App\Domain\Budgets\BudgetService;
use App\Domain\Budgets\BudgetStatus;
use App\Filament\Resources\Budgets\BudgetsResource;
use App\Models\Budget;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BudgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (Budget $record): string => BudgetsResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('project.code')->label('Project')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BudgetStatus $state): string => ucfirst($state->value))
                    ->color(fn (BudgetStatus $state): string => match ($state) {
                        BudgetStatus::Draft => 'gray',
                        BudgetStatus::Open => 'success',
                        BudgetStatus::Closed => 'warning',
                    }),

                // Summed in bcmath, never SQL SUM().
                TextColumn::make('total')
                    ->state(fn (Budget $record): string => app(BudgetService::class)->total($record))
                    ->money('PHP'),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(BudgetStatus::cases())->mapWithKeys(fn (BudgetStatus $case): array => [$case->value => ucfirst($case->value)])->all()
                ),
            ]);
    }
}
