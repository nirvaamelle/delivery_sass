<?php

namespace App\Filament\Resources\Equipment\Schemas;

use App\Domain\Equipment\DepreciationMethod;
use App\Domain\Equipment\Ownership;
use App\Models\Equipment;
use App\Models\Organization;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The machine form — register and edit.
 *
 * **Not here at all:** status. A machine is deployed and released by the row
 * actions on the register, and a status dropdown would let it be "available"
 * while still assigned to a project.
 *
 * **Locked on edit:** the code and company. **Locked once a depreciation
 * schedule exists:** the acquisition figures the schedule was computed from.
 */
class EquipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        $scheduled = fn (?Equipment $record): bool => $record !== null && $record->schedules()->exists();

        return $schema->components([
            Section::make('Machine')
                ->columns(2)
                ->schema([
                    Select::make('organization_id')
                        ->label('Company')
                        ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => Organization::query()->count() === 1 ? (int) Organization::query()->value('id') : null)
                        ->required()
                        ->disabledOn('edit'),

                    TextInput::make('code')
                        ->required()
                        ->maxLength(255)
                        ->disabledOn('edit')
                        ->helperText('Costs and assignments are looked up by this on site, so it cannot be changed.'),

                    TextInput::make('description')->required()->maxLength(255),
                    TextInput::make('category')->maxLength(255),
                    TextInput::make('serial_number')->label('Serial number')->maxLength(255),

                    Select::make('ownership')
                        ->options(collect(Ownership::cases())->mapWithKeys(fn (Ownership $case): array => [$case->value => ucfirst($case->value)])->all())
                        ->required(),
                ]),

            Section::make('Acquisition and depreciation')
                ->description(fn (?Equipment $record): ?string => $scheduled($record)
                    ? 'A depreciation schedule exists, so these figures are locked — the schedule was computed from them.'
                    : null)
                ->columns(2)
                ->schema([
                    TextInput::make('acquisition_cost')->label('Acquisition cost')->numeric()->minValue(0)->disabled($scheduled),
                    TextInput::make('salvage_value')->label('Salvage value')->numeric()->minValue(0)->disabled($scheduled),
                    DatePicker::make('acquired_on')->label('Acquired on')->disabled($scheduled),
                    TextInput::make('useful_life_months')->label('Useful life (months)')->integer()->minValue(1)->disabled($scheduled),

                    Select::make('depreciation_method')
                        ->label('Depreciation method')
                        ->options(collect(DepreciationMethod::cases())->mapWithKeys(fn (DepreciationMethod $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])->all())
                        ->default(DepreciationMethod::StraightLine->value)
                        ->disabled($scheduled),
                ]),
        ]);
    }
}
