<?php

namespace App\Filament\Resources\ApprovalMatrices\Tables;

use App\Models\ApprovalMatrix;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApprovalMatricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('document_type')
            ->columns([
                TextColumn::make('document_type')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tier')
                    ->sortable(),

                // The band as one readable range. Two separate numeric columns
                // make the reader do the comparison the screen is entirely
                // about; showing the range makes a gap or an overlap visible.
                TextColumn::make('band')
                    ->label('Amount band')
                    ->state(fn (ApprovalMatrix $record): string => sprintf(
                        '%s to %s',
                        number_format((float) $record->min_amount, 2),
                        $record->max_amount === null
                            ? 'no ceiling'
                            : number_format((float) $record->max_amount, 2),
                    )),

                TextColumn::make('approver_roles')
                    ->label('Approvers, in order')
                    ->badge(),

                TextColumn::make('required_documents')
                    ->label('Requires')
                    ->badge()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
