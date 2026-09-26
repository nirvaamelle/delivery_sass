<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Site expenses, and the ones that were returned.
 *
 * Read-only. An expense typed into a form is one with no receipt check, no cost
 * code check and no budget line behind it — the three refusals slide 8 puts in
 * front of booking — and it would sit in the consolidation looking exactly like
 * a captured one.
 */
class ExpensesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Expense::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Expenses';

    protected static string|UnitEnum|null $navigationGroup = 'OPEX';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
        ];
    }
}
