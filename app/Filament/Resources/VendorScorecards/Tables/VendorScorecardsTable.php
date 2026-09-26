<?php

namespace App\Filament\Resources\VendorScorecards\Tables;

use App\Models\VendorScorecard;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VendorScorecardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('rated_at', 'desc')
            ->columns([
                TextColumn::make('vendor.name')->label('Vendor')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.number')->label('PO')->searchable(),

                TextColumn::make('period')
                    ->label('Quarter')
                    ->state(fn (VendorScorecard $record): string => sprintf('Q%d %d', $record->period_quarter, $record->period_year)),

                /*
                 * All four dimensions on the row. F12's finding is that the deck
                 * disagreed about whether there were three or four, so showing
                 * only an overall would hide exactly the column the finding is
                 * about.
                 */
                TextColumn::make('price_score')->label('Price'),
                TextColumn::make('delivery_score')->label('Delivery'),
                TextColumn::make('quality_score')->label('Quality'),
                TextColumn::make('documents_score')->label('Documents'),

                TextColumn::make('overall_score')
                    ->label('Overall')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        bccomp($state, '85.00', 2) >= 0 => 'success',
                        bccomp($state, '70.00', 2) >= 0 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('days_late')->label('Days late')->toggleable(),
            ]);
    }
}
