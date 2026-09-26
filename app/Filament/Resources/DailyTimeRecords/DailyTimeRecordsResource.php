<?php

namespace App\Filament\Resources\DailyTimeRecords;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\DailyTimeRecords\Pages\ListDailyTimeRecords;
use App\Filament\Resources\DailyTimeRecords\Tables\DailyTimeRecordsTable;
use App\Models\DailyTimeRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every day, and what the site said about it — including the days being HELD.
 *
 * Read-only, like every other document screen in this build — and here the rule
 * has more weight than usual. A payroll figure typed into a form is a figure
 * with no DTR, no rate history and no variance review behind it, and it would
 * reach somebody's bank account looking exactly like a computed one.
 */
class DailyTimeRecordsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = DailyTimeRecord::class;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Daily time records';

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $slug = 'daily-time-records';

    public static function table(Table $table): Table
    {
        return DailyTimeRecordsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDailyTimeRecords::route('/'),
        ];
    }
}
