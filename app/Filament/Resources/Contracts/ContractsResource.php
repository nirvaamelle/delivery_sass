<?php

namespace App\Filament\Resources\Contracts;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\RelationManagers\MilestonesRelationManager;
use App\Filament\Resources\Contracts\Schemas\ContractForm;
use App\Filament\Resources\Contracts\Tables\ContractsTable;
use App\Models\Contract;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The client contract — the document everything else rests on.
 *
 * It had no screen at all, which made the system unusable from a clean install:
 * F14 refuses a purchase requisition on a project with no signed contract, so
 * without this nobody could spend, and the billing schedule that milestones are
 * billed against is built from the contract sum.
 *
 * Draft, send for signature, sign (which builds the billing schedule in the same
 * act), terminate. Terms are fixed once it leaves draft, and there is no delete.
 *
 * **Rows follow the project scope**, so a project manager sees the contracts of
 * the jobs they are on and nobody else's.
 */
class ContractsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Contract::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Contracts';

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 15;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    public static function getEloquentQuery(): Builder
    {
        // whereHas applies Project's global scope, so an unassigned project's
        // contracts drop out.
        return parent::getEloquentQuery()->whereHas('project');
    }

    public static function form(Schema $schema): Schema
    {
        return ContractForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MilestonesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContracts::route('/'),
            'create' => CreateContract::route('/create'),
            'edit' => EditContract::route('/{record}/edit'),
        ];
    }
}
