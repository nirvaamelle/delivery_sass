<?php

namespace App\Filament\Resources\CashAdvances;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\CashAdvances\Pages\ListCashAdvances;
use App\Filament\Resources\CashAdvances\Tables\CashAdvancesTable;
use App\Models\CashAdvance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Advanced money, and what is still unaccounted for.
 *
 * Read-only. An expense typed into a form is one with no receipt check, no cost
 * code check and no budget line behind it — the three refusals slide 8 puts in
 * front of booking — and it would sit in the consolidation looking exactly like
 * a captured one.
 */
class CashAdvancesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = CashAdvance::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Cash advances';

    protected static string|UnitEnum|null $navigationGroup = 'OPEX';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    public static function table(Table $table): Table
    {
        return CashAdvancesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashAdvances::route('/'),
        ];
    }
}
