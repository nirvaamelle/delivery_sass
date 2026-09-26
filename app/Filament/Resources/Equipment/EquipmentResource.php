<?php

namespace App\Filament\Resources\Equipment;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Resources\Equipment\Pages\ListEquipment;
use App\Filament\Resources\Equipment\Schemas\EquipmentForm;
use App\Filament\Resources\Equipment\Tables\EquipmentTable;
use App\Models\Equipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The equipment register - F5's answer to an unbuildable OPEX input.
 *
 * A machine is master data, so it can be registered and corrected here — through
 * EquipmentRegisterService, which fixes the code and locks the acquisition
 * figures once a depreciation schedule exists. Status is never a field: the row
 * actions deploy and release, through EquipmentService.
 */
class EquipmentResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Equipment::class;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $navigationLabel = 'Equipment';

    protected static string|UnitEnum|null $navigationGroup = 'Assets';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    public static function table(Table $table): Table
    {
        return EquipmentTable::configure($table);
    }

    public static function form(Schema $schema): Schema
    {
        return EquipmentForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEquipment::route('/'),
            'create' => CreateEquipment::route('/create'),
            'edit' => EditEquipment::route('/{record}/edit'),
        ];
    }
}
