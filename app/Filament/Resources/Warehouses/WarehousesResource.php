<?php

namespace App\Filament\Resources\Warehouses;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Resources\Warehouses\Schemas\WarehouseForm;
use App\Filament\Resources\Warehouses\Tables\WarehousesTable;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The facility register — spec §4.1.
 *
 * First screen of the logistics build, and the one every later screen depends
 * on: gate visits, pick tasks, manning allocations and trip plans all name a
 * warehouse. No delete action — those tables point here, and a site that
 * closes is deactivated so its history keeps resolving.
 */
class WarehousesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Warehouse::class;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $navigationLabel = 'Warehouses';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    public static function form(Schema $schema): Schema
    {
        return WarehouseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarehousesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }
}
