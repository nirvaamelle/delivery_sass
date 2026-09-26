<?php

namespace App\Filament\Resources\BackCharges\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What was charged back, and against which works.
 *
 * The subcontract number is on the row because that is what the charge reduces,
 * and the punchlist item because that is what it is FOR. A back-charge whose
 * defect cannot be named from the screen is one the subcontractor will dispute
 * and nobody can answer.
 */
class BackChargesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('raised_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('raised_at')->label('Raised')->date()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('subcontract.number')->label('Subcontract')->searchable(),
                TextColumn::make('subcontract.vendor.code')->label('Subcontractor')->searchable(),

                TextColumn::make('punchlistItem.description')->label('Defect')->limit(36),
                TextColumn::make('description')->label('Remedy')->limit(36)->toggleable(),

                TextColumn::make('amount')->money('PHP')->sortable(),

                // The posting, not a claim about one: the id is stamped inside
                // the same transaction that wrote the ledger row.
                TextColumn::make('posted_at')->label('In ledger')->dateTime()->placeholder('Not posted'),
            ]);
    }
}
