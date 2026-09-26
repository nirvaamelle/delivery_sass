<?php

namespace App\Filament\Resources\Permits;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Permits\Pages\ListPermits;
use App\Filament\Resources\Permits\Tables\PermitsTable;
use App\Models\Permit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Site permits, with the third expiry clock in the system.
 *
 * Read-only, like every other document screen in this build. The document is
 * created by a service that runs the gates slide 6 specifies — the milestone's
 * document set, the verified accomplishment, the deducted lines still blocked —
 * and a form here would be the one way to write the row that skips all three.
 */
class PermitsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Permit::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Permits';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    public static function table(Table $table): Table
    {
        return PermitsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermits::route('/'),
        ];
    }
}
