<?php

namespace App\Filament\Resources\BackCharges;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\BackCharges\Pages\ListBackCharges;
use App\Filament\Resources\BackCharges\Tables\BackChargesTable;
use App\Models\BackCharge;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Back-charges against subcontractors — F6.
 *
 * Read-only, and here it matters more than usual: a back-charge is a LEDGER
 * POSTING. Typed into a form it would be a charge against a subcontractor with
 * no punchlist item behind it, no clearing date to be computed at, and nothing
 * in `project_cost_ledger` to match it.
 */
class BackChargesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = BackCharge::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Back-charges';

    protected static string|UnitEnum|null $navigationGroup = 'Close-out';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    public static function table(Table $table): Table
    {
        return BackChargesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackCharges::route('/'),
        ];
    }
}
