<?php

namespace App\Filament\Resources\ApVouchers;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\ApVouchers\Pages\ListApVouchers;
use App\Filament\Resources\ApVouchers\Tables\ApVouchersTable;
use App\Models\ApVoucher;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The payable. The last document before money leaves.
 *
 * Read-only. Every document in this chain is created by a domain service that
 * runs the gates PLAN.md section 5 specifies, and decisions are taken in the
 * single approvals inbox rather than on seven document screens. A form here
 * would be a second way to write the row: the one that skips the checks.
 */
class ApVouchersResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = ApVoucher::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'AP vouchers';

    protected static string|UnitEnum|null $navigationGroup = 'Payables';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function table(Table $table): Table
    {
        return ApVouchersTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApVouchers::route('/'),
        ];
    }
}
