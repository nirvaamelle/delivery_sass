<?php

namespace App\Filament\Resources\RetentionReleases;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Concerns\RetiredModule;
use App\Filament\Resources\RetentionReleases\Pages\ListRetentionReleases;
use App\Filament\Resources\RetentionReleases\Tables\RetentionReleasesTable;
use App\Models\RetentionRelease;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Retention claimed at the end of the defects liability period - P5-07.
 *
 * Read-only. The claim is refused before the DLP ends and for more than is
 * held; the collection writes a ledger movement through the retention service.
 * A form would move neither and refuse nothing.
 */
class RetentionReleasesResource extends Resource
{
    use AuthorizesScreenByRole;
    use RetiredModule;

    protected static ?string $model = RetentionRelease::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Retention releases';

    protected static string|UnitEnum|null $navigationGroup = 'Close-out';

    protected static ?int $navigationSort = 60;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function table(Table $table): Table
    {
        return RetentionReleasesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRetentionReleases::route('/'),
        ];
    }
}
