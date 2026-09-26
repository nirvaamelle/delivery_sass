<?php

namespace App\Filament\Resources\Budgets\Schemas;

use App\Domain\Budgets\BudgetStatus;
use App\Models\Budget;
use App\Models\Project;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Status is not a field: a budget is opened and closed by its own acts on the
 * edit page. Lines are set below the form, and only while the budget is a draft.
 */
class BudgetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Budget')
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

                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (?Budget $record): bool => $record !== null && $record->status !== BudgetStatus::Draft),
                ]),
        ]);
    }
}
