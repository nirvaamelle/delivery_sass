<?php

namespace App\Filament\Resources\OvertimeAuthorities;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\OvertimeAuthorities\Pages\ListOvertimeAuthorities;
use App\Filament\Resources\OvertimeAuthorities\Tables\OvertimeAuthoritiesTable;
use App\Models\OvertimeAuthority;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Slide 7’s "approved in writing", and what is payable because of it.
 *
 * Read-only, like every other document screen in this build — and here the rule
 * has more weight than usual. A payroll figure typed into a form is a figure
 * with no DTR, no rate history and no variance review behind it, and it would
 * reach somebody's bank account looking exactly like a computed one.
 */
class OvertimeAuthoritiesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = OvertimeAuthority::class;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Overtime authorities';

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $slug = 'overtime-authorities';

    public static function table(Table $table): Table
    {
        return OvertimeAuthoritiesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOvertimeAuthorities::route('/'),
        ];
    }
}
