<?php

namespace App\Filament\Resources\TurnoverPacks;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Concerns\RetiredModule;
use App\Filament\Resources\TurnoverPacks\Pages\ListTurnoverPacks;
use App\Filament\Resources\TurnoverPacks\Tables\TurnoverPacksTable;
use App\Models\TurnoverPack;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Turnover and acceptance - slide 9 step 3.
 *
 * Read-only. Acceptance is refused while a required line is outstanding or the
 * punchlist is open; a form would let the pack be marked accepted over both.
 */
class TurnoverPacksResource extends Resource
{
    use AuthorizesScreenByRole;
    use RetiredModule;

    protected static ?string $model = TurnoverPack::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Turnover packs';

    protected static string|UnitEnum|null $navigationGroup = 'Close-out';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function table(Table $table): Table
    {
        return TurnoverPacksTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTurnoverPacks::route('/'),
        ];
    }
}
