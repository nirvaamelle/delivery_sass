<?php

namespace App\Filament\Resources\VendorAdvances;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\VendorAdvances\Pages\ListVendorAdvances;
use App\Filament\Resources\VendorAdvances\Tables\VendorAdvancesTable;
use App\Models\VendorAdvance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Money paid to a vendor before anything arrives — F8.
 *
 * It had no screen at all, which is the wrong thing for an advance to have: it
 * is the payment an auditor opens first, and the one most easily forgotten once
 * the order closes. The register exists to answer "what have we advanced and
 * how much is still unrecovered".
 *
 * Releasing is an action, not a form, and there is no edit or delete: an advance
 * is recovered by a voucher offsetting it, never by editing the record of it.
 */
class VendorAdvancesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = VendorAdvance::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Vendor advances';

    protected static ?string $modelLabel = 'vendor advance';

    protected static string|UnitEnum|null $navigationGroup = 'Payables';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    public static function canCreate(): bool
    {
        // Released by its action, which needs the order it is advanced against.
        return false;
    }

    public static function table(Table $table): Table
    {
        return VendorAdvancesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorAdvances::route('/'),
        ];
    }
}
