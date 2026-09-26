<?php

namespace App\Filament\Resources\Mobilizations;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Mobilizations\Pages\ListMobilizations;
use App\Filament\Resources\Mobilizations\Tables\MobilizationsTable;
use App\Models\Mobilization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Slide 3's step 5 — the document F2 said had no home.
 *
 * Read-only, like every other document screen in this build. The document is
 * created by a service that runs the gates slide 6 specifies — the milestone's
 * document set, the verified accomplishment, the deducted lines still blocked —
 * and a form here would be the one way to write the row that skips all three.
 */
class MobilizationsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Mobilization::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Mobilization';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    public static function table(Table $table): Table
    {
        return MobilizationsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMobilizations::route('/'),
        ];
    }
}
