<?php

namespace App\Filament\Resources\PayrollRuns;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Filament\Resources\PayrollRuns\Tables\PayrollRunsTable;
use App\Models\PayrollRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * One cutoff’s register, and where it stands against F10’s variance gate.
 *
 * Read-only, like every other document screen in this build — and here the rule
 * has more weight than usual. A payroll figure typed into a form is a figure
 * with no DTR, no rate history and no variance review behind it, and it would
 * reach somebody's bank account looking exactly like a computed one.
 */
class PayrollRunsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = PayrollRun::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Payroll runs';

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    public static function table(Table $table): Table
    {
        return PayrollRunsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollRuns::route('/'),
        ];
    }
}
