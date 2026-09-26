<?php

namespace App\Filament\Resources\ArEscalations;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\ArEscalations\Pages\ListArEscalations;
use App\Filament\Resources\ArEscalations\Tables\ArEscalationsTable;
use App\Models\ArEscalation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * F13's thirty-day escalations — the ones that arrive rather than waiting to be opened.
 *
 * Read-only, like every other document screen in this build. The document is
 * created by a service that runs the gates slide 6 specifies — the milestone's
 * document set, the verified accomplishment, the deducted lines still blocked —
 * and a form here would be the one way to write the row that skips all three.
 */
class ArEscalationsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = ArEscalation::class;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'AR escalations';

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $slug = 'ar-escalations';

    public static function table(Table $table): Table
    {
        return ArEscalationsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArEscalations::route('/'),
        ];
    }
}
