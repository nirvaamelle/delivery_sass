<?php

namespace App\Filament\Resources\VendorScorecards;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\VendorScorecards\Pages\ListVendorScorecards;
use App\Filament\Resources\VendorScorecards\Tables\VendorScorecardsTable;
use App\Models\VendorScorecard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Vendor scorecards — F12's four dimensions.
 *
 * Read-only, and that is the point rather than a limitation. Every score is
 * derived from the chain when the card is written; a screen that let somebody
 * edit one would turn the suspension rules into an enforcement of opinion.
 */
class VendorScorecardResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = VendorScorecard::class;

    protected static ?string $navigationLabel = 'Scorecards';

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    public static function table(Table $table): Table
    {
        return VendorScorecardsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorScorecards::route('/'),
        ];
    }
}
