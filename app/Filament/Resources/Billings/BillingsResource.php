<?php

namespace App\Filament\Resources\Billings;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Billings\Pages\ListBillings;
use App\Filament\Resources\Billings\Tables\BillingsTable;
use App\Models\Billing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Progress billings, and slide 6's approved-or-returned branch.
 *
 * Read-only, like every other document screen in this build. The document is
 * created by a service that runs the gates slide 6 specifies — the milestone's
 * document set, the verified accomplishment, the deducted lines still blocked —
 * and a form here would be the one way to write the row that skips all three.
 */
class BillingsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Billing::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Billings';

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function table(Table $table): Table
    {
        return BillingsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillings::route('/'),
        ];
    }
}
