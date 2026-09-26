<?php

namespace App\Filament\Resources\OpexPeriods;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\OpexPeriods\Pages\ListOpexPeriods;
use App\Filament\Resources\OpexPeriods\Tables\OpexPeriodsTable;
use App\Models\OpexPeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Slide 8's seven stages, and where each month has got to.
 *
 * Read-only. An expense typed into a form is one with no receipt check, no cost
 * code check and no budget line behind it — the three refusals slide 8 puts in
 * front of booking — and it would sit in the consolidation looking exactly like
 * a captured one.
 */
class OpexPeriodsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = OpexPeriod::class;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Monthly calendar';

    protected static string|UnitEnum|null $navigationGroup = 'OPEX';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $slug = 'opex-periods';

    public static function table(Table $table): Table
    {
        return OpexPeriodsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOpexPeriods::route('/'),
        ];
    }
}
