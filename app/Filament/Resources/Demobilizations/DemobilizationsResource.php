<?php

namespace App\Filament\Resources\Demobilizations;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Concerns\RetiredModule;
use App\Filament\Resources\Demobilizations\Pages\ListDemobilizations;
use App\Filament\Resources\Demobilizations\Tables\DemobilizationsTable;
use App\Models\Demobilization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Demobilization and clearance - slide 9 step 5.
 *
 * Read-only. Completing it is refused while a person is uncleared, plant is on
 * site or an advance is outstanding, and clearing a person is refused while they
 * hold one. None of those checks exist on a form.
 */
class DemobilizationsResource extends Resource
{
    use AuthorizesScreenByRole;
    use RetiredModule;

    protected static ?string $model = Demobilization::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Demobilizations';

    protected static string|UnitEnum|null $navigationGroup = 'Close-out';

    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    public static function table(Table $table): Table
    {
        return DemobilizationsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemobilizations::route('/'),
        ];
    }
}
