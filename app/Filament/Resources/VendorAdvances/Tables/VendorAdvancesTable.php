<?php

namespace App\Filament\Resources\VendorAdvances\Tables;

use App\Domain\Procurement\PayablesService;
use App\Domain\Support\Money;
use App\Models\VendorAdvance;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VendorAdvancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('released_at', 'desc')
            ->emptyStateHeading('No advances released')
            ->emptyStateDescription('An advance pays a vendor before anything arrives. Release one against a purchase order.')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.number')->label('Against PO')->searchable(),
                TextColumn::make('vendor.name')->label('Vendor')->searchable(),
                TextColumn::make('amount')->money('PHP')->sortable(),

                TextColumn::make('offset_amount')->label('Recovered')->money('PHP'),

                // The number the register exists for: still out, still owed back.
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->money('PHP')
                    ->state(fn (VendorAdvance $record): string => bcsub(
                        (string) $record->amount,
                        (string) $record->offset_amount,
                        Money::SCALE,
                    )),

                TextColumn::make('purpose')->limit(40)->wrap(),
                TextColumn::make('released_at')->label('Released')->date()->sortable(),
            ])
            ->recordActions([])
            ->description(fn (): string => sprintf(
                'Outstanding across every order: %s. An advance is recovered automatically by the next voucher raised against its order.',
                number_format((float) app(PayablesService::class)->outstandingAdvanceTotal(), 2),
            ));
    }
}
