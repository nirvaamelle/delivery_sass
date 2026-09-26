<?php

namespace App\Filament\Resources\DisbursementBatches;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\DisbursementBatches\Pages\ListDisbursementBatches;
use App\Filament\Resources\DisbursementBatches\Tables\DisbursementBatchesTable;
use App\Models\DisbursementBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Bank upload or cash payout, and what is still waiting on a signature.
 *
 * Read-only, like every other document screen in this build — and here the rule
 * has more weight than usual. A payroll figure typed into a form is a figure
 * with no DTR, no rate history and no variance review behind it, and it would
 * reach somebody's bank account looking exactly like a computed one.
 */
class DisbursementBatchesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = DisbursementBatch::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Disbursements';

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $slug = 'disbursements';

    public static function table(Table $table): Table
    {
        return DisbursementBatchesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisbursementBatches::route('/'),
        ];
    }
}
