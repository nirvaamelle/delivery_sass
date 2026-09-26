<?php

namespace App\Filament\Resources\ThreeWayMatches\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ThreeWayMatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('matched_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('invoice_reference')->label('Invoice')->searchable(),
                TextColumn::make('purchaseOrder.number')->label('PO')->searchable(),
                TextColumn::make('receivingReport.number')->label('Receipt')->searchable(),

                /*
                 * All three figures, side by side, because the match's whole
                 * argument is that they can differ: the order says what was
                 * bought, the accepted value says what may be paid for, and the
                 * invoice says what was asked for.
                 */
                TextColumn::make('ordered_amount')->label('Ordered')->money('PHP'),
                TextColumn::make('accepted_amount')->label('Accepted')->money('PHP'),
                TextColumn::make('invoice_amount')->label('Invoiced')->money('PHP'),

                IconColumn::make('matched')->boolean(),

                TextColumn::make('matched_at')->label('Matched')->dateTime()->sortable(),
            ]);
    }
}
