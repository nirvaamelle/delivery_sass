<?php

namespace App\Filament\Resources\Budgets;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Budgets\Pages\CreateBudget;
use App\Filament\Resources\Budgets\Pages\EditBudget;
use App\Filament\Resources\Budgets\Pages\ListBudgets;
use App\Filament\Resources\Budgets\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Budgets\Schemas\BudgetForm;
use App\Filament\Resources\Budgets\Tables\BudgetsTable;
use App\Models\Budget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Project budgets — drafted line by line, opened and closed as acts.
 *
 * Every change goes through BudgetService: only a draft changes, a project has
 * one open budget, and opening needs at least one line.
 *
 * **Rows follow the project scope.** Budgets are not themselves scoped, so the
 * query is limited to budgets whose project the user can see — a project
 * manager sees the budgets of the jobs they are on, not every job's.
 */
class BudgetsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Budget::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Budgets';

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    public static function getEloquentQuery(): Builder
    {
        // whereHas applies Project's global scope, so an unassigned project's
        // budgets drop out.
        return parent::getEloquentQuery()->whereHas('project');
    }

    public static function form(Schema $schema): Schema
    {
        return BudgetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BudgetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBudgets::route('/'),
            'create' => CreateBudget::route('/create'),
            'edit' => EditBudget::route('/{record}/edit'),
        ];
    }
}
