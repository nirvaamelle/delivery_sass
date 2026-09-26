<?php

namespace App\Filament\Resources\Ledger;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Ledger\Pages\ListLedger;
use App\Filament\Resources\Ledger\Tables\LedgerTable;
use App\Models\ProjectCostLedgerEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * PLAN.md section 1's organising principle: every process ends here.
 *
 * Read-only. Every document in this chain is created by a domain service that
 * runs the gates PLAN.md section 5 specifies, and decisions are taken in the
 * single approvals inbox rather than on seven document screens. A form here
 * would be a second way to write the row: the one that skips the checks.
 */
class LedgerResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = ProjectCostLedgerEntry::class;

    protected static ?string $recordTitleAttribute = 'document_number';

    /*
     * Set explicitly. The resource is named for what the screen IS rather than
     * for its model, and Filament would otherwise derive "ledger/ledgers" from
     * the two together.
     */
    protected static ?string $slug = 'ledger';

    protected static ?string $navigationLabel = 'Project cost ledger';

    protected static string|UnitEnum|null $navigationGroup = 'Reporting';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    public static function table(Table $table): Table
    {
        return LedgerTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedger::route('/'),
        ];
    }
}
