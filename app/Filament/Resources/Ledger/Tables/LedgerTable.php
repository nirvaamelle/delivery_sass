<?php

namespace App\Filament\Resources\Ledger\Tables;

use App\Domain\Posting\LedgerCategory;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The project cost ledger.
 *
 * The columns are deliberately the SNAPSHOTTED ones — `project_code` and
 * `cost_code` as they were at posting time, not the current names read back
 * through a relation. P0-13 stores them as values precisely so a rename does not
 * restate last year, and a screen that joined instead would undo that on the one
 * page an auditor is most likely to be reading.
 */
class LedgerTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('posted_at', 'desc')
            ->columns([
                TextColumn::make('document_number')->label('Document')->searchable()->sortable(),
                TextColumn::make('project_code')->label('Project')->searchable()->sortable(),
                TextColumn::make('cost_code')->label('Cost code')->searchable()->sortable(),

                TextColumn::make('category')
                    ->badge()
                    ->color(fn (LedgerCategory $state): string => match ($state) {
                        LedgerCategory::Revenue => 'success',
                        LedgerCategory::Material => 'info',
                        LedgerCategory::Subcontract => 'warning',
                        LedgerCategory::Labor => 'primary',
                        LedgerCategory::Overhead => 'gray',
                    }),

                TextColumn::make('amount')->money('PHP')->sortable(),

                TextColumn::make('document_date')->label('Dated')->date()->sortable(),
                TextColumn::make('posted_at')->label('Posted')->dateTime()->sortable(),

                TextColumn::make('description')->limit(40)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')->options(
                    collect(LedgerCategory::cases())
                        ->mapWithKeys(fn (LedgerCategory $case): array => [$case->value => ucfirst($case->value)])
                        ->all()
                ),
            ]);
    }
}
