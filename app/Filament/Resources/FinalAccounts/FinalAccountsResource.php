<?php

namespace App\Filament\Resources\FinalAccounts;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Concerns\RetiredModule;
use App\Filament\Resources\FinalAccounts\Pages\ListFinalAccounts;
use App\Filament\Resources\FinalAccounts\Tables\FinalAccountsTable;
use App\Models\FinalAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The final project P&L and forecast to completion - P5-09.
 *
 * Read-only, and this one could not be a form at all: every figure is summed
 * from the ledger at filing, and typing one would assert a profit the postings
 * do not support.
 */
class FinalAccountsResource extends Resource
{
    use AuthorizesScreenByRole;
    use RetiredModule;

    protected static ?string $model = FinalAccount::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Final accounts';

    protected static string|UnitEnum|null $navigationGroup = 'Close-out';

    protected static ?int $navigationSort = 70;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    public static function table(Table $table): Table
    {
        return FinalAccountsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinalAccounts::route('/'),
        ];
    }
}
