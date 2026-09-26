<?php

namespace App\Filament\Resources\CostCodes;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\CostCodes\Pages\CreateCostCode;
use App\Filament\Resources\CostCodes\Pages\EditCostCode;
use App\Filament\Resources\CostCodes\Pages\ListCostCodes;
use App\Filament\Resources\CostCodes\Schemas\CostCodeForm;
use App\Filament\Resources\CostCodes\Tables\CostCodesTable;
use App\Models\CostCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The cost code tree. Saves through CostCodeService: the code is fixed once
 * added, a parent stays in the same company, and the tree cannot loop. No
 * delete — budget lines point at cost codes.
 */
class CostCodesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = CostCode::class;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $navigationLabel = 'Cost codes';

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    public static function form(Schema $schema): Schema
    {
        return CostCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostCodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostCodes::route('/'),
            'create' => CreateCostCode::route('/create'),
            'edit' => EditCostCode::route('/{record}/edit'),
        ];
    }
}
