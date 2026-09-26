<?php

namespace App\Filament\Resources\PayrollDeductions;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\PayrollDeductions\Pages\ListPayrollDeductions;
use App\Filament\Resources\PayrollDeductions\Tables\PayrollDeductionsTable;
use App\Models\PayrollDeduction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * F17's cross-chain write: advances charged to the next payroll.
 *
 * Read-only. An expense typed into a form is one with no receipt check, no cost
 * code check and no budget line behind it — the three refusals slide 8 puts in
 * front of booking — and it would sit in the consolidation looking exactly like
 * a captured one.
 */
class PayrollDeductionsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = PayrollDeduction::class;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Payroll deductions';

    protected static string|UnitEnum|null $navigationGroup = 'OPEX';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static ?string $slug = 'payroll-deductions';

    public static function table(Table $table): Table
    {
        return PayrollDeductionsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollDeductions::route('/'),
        ];
    }
}
