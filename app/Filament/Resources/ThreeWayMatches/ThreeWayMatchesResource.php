<?php

namespace App\Filament\Resources\ThreeWayMatches;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Concerns\RetiredModule;
use App\Filament\Resources\ThreeWayMatches\Pages\ListThreeWayMatches;
use App\Filament\Resources\ThreeWayMatches\Tables\ThreeWayMatchesTable;
use App\Models\ThreeWayMatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * PO, receipt and invoice, agreed.
 *
 * Read-only. Every document in this chain is created by a domain service that
 * runs the gates PLAN.md section 5 specifies, and decisions are taken in the
 * single approvals inbox rather than on seven document screens. A form here
 * would be a second way to write the row: the one that skips the checks.
 */
class ThreeWayMatchesResource extends Resource
{
    use AuthorizesScreenByRole;
    use RetiredModule;

    protected static ?string $model = ThreeWayMatch::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Three-way matches';

    protected static string|UnitEnum|null $navigationGroup = 'Payables';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    public static function table(Table $table): Table
    {
        return ThreeWayMatchesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListThreeWayMatches::route('/'),
        ];
    }
}
