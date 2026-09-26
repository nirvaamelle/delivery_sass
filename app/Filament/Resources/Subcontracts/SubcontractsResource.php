<?php

namespace App\Filament\Resources\Subcontracts;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Concerns\RetiredModule;
use App\Filament\Resources\Subcontracts\Pages\ListSubcontracts;
use App\Filament\Resources\Subcontracts\Tables\SubcontractsTable;
use App\Models\Subcontract;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Works let to a subcontractor — F6's other half, and it had no screen at all.
 *
 * Back-charges are raised against a subcontract, the final account nets them off
 * against what is still payable, and close-out refuses while any subcontractor
 * is unrated. None of that was reachable, because nothing could award one.
 *
 * Awarding is an action, not a form, and there is no edit or delete: the amount
 * is what back-charges and the final account both resolve against.
 */
class SubcontractsResource extends Resource
{
    use AuthorizesScreenByRole;
    use RetiredModule;

    protected static ?string $model = Subcontract::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Subcontracts';

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 60;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    public static function getEloquentQuery(): Builder
    {
        // whereHas applies Project's global scope, so an unassigned project's
        // subcontracts drop out.
        return parent::getEloquentQuery()->whereHas('project');
    }

    public static function canCreate(): bool
    {
        // Awarded by its action, which runs the service's checks.
        return false;
    }

    public static function table(Table $table): Table
    {
        return SubcontractsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubcontracts::route('/'),
        ];
    }
}
