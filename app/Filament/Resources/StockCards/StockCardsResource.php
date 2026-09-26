<?php

namespace App\Filament\Resources\StockCards;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Concerns\RetiredModule;
use App\Filament\Resources\StockCards\Pages\ListStockCards;
use App\Filament\Resources\StockCards\Tables\StockCardsTable;
use App\Models\StockCard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The yard, as a ledger of movements rather than a stored balance.
 *
 * Read-only. Every document in this chain is created by a domain service that
 * runs the gates PLAN.md section 5 specifies, and decisions are taken in the
 * single approvals inbox rather than on seven document screens. A form here
 * would be a second way to write the row: the one that skips the checks.
 */
class StockCardsResource extends Resource
{
    use AuthorizesScreenByRole;
    use RetiredModule;

    protected static ?string $model = StockCard::class;

    protected static ?string $recordTitleAttribute = 'item_description';

    protected static ?string $navigationLabel = 'Stock cards';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function table(Table $table): Table
    {
        return StockCardsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockCards::route('/'),
        ];
    }
}
