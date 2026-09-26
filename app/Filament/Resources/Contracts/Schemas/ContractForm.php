<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Domain\Contracts\ContractStatus;
use App\Models\Contract;
use App\Models\Project;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Status is not a field: draft, for signature, signed and terminated are all
 * acts on the edit page. Every field is locked once the contract leaves draft,
 * because the billing schedule, retention and the warranty period are computed
 * from these numbers.
 */
class ContractForm
{
    public static function configure(Schema $schema): Schema
    {
        $fixed = fn (?Contract $record): bool => $record !== null && $record->status !== ContractStatus::Draft;

        return $schema->components([
            Section::make('Contract')
                ->description(fn (?Contract $record): ?string => $fixed($record)
                    ? 'The contract has left draft, so its terms are fixed — the billing schedule, retention and the warranty period all date from them.'
                    : null)
                ->columns(2)
                ->schema([
                    Select::make('project_id')
                        ->label('Project')
                        // Scoped: only projects the user can see.
                        ->options(fn (): array => Project::query()->orderBy('code')->get()
                            ->mapWithKeys(fn (Project $project): array => [$project->getKey() => $project->code.' — '.$project->name])
                            ->all())
                        ->searchable()
                        ->required()
                        ->disabledOn('edit'),

                    TextInput::make('number')
                        ->label('Contract number')
                        ->required()
                        ->maxLength(255)
                        ->disabled($fixed),

                    TextInput::make('contract_sum')
                        ->label('Contract sum')
                        ->required()
                        ->rules(['numeric', 'gt:0'])
                        ->disabled($fixed)
                        ->helperText('Every milestone amount is a percentage of this.'),

                    TextInput::make('retention_rate')
                        ->label('Retention rate')
                        ->rules(['numeric', 'gte:0', 'lte:1'])
                        ->default('0.100000')
                        ->disabled($fixed)
                        // PLACEHOLDER: Part D item 3.
                        ->helperText('A fraction, not a percentage: 0.10 withholds ten per cent of every invoice.'),

                    TextInput::make('defects_liability_days')
                        ->label('Defects liability (days)')
                        ->integer()
                        ->minValue(1)
                        ->default(365)
                        ->disabled($fixed)
                        ->helperText('Dates the warranty and the retention release after completion.'),

                    DatePicker::make('noa_date')->label('Notice of award')->disabled($fixed),
                    DatePicker::make('ntp_date')->label('Notice to proceed')->disabled($fixed),
                ]),
        ]);
    }
}
