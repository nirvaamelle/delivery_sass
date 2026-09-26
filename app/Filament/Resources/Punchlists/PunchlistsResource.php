<?php

namespace App\Filament\Resources\Punchlists;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Concerns\RetiredModule;
use App\Filament\Resources\Punchlists\Pages\ListPunchlists;
use App\Filament\Resources\Punchlists\Tables\PunchlistsTable;
use App\Models\Punchlist;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Punchlists — slide 9 step 2.
 *
 * Read-only. Clearing an item is a named act with a note, refused twice over and
 * refused again by a CHECK constraint; a form that wrote the row directly would
 * produce a cleared defect nobody signed for.
 */
class PunchlistsResource extends Resource
{
    use AuthorizesScreenByRole;
    use RetiredModule;

    protected static ?string $model = Punchlist::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Punchlists';

    protected static string|UnitEnum|null $navigationGroup = 'Close-out';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function table(Table $table): Table
    {
        return PunchlistsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPunchlists::route('/'),
        ];
    }
}
