<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Organization;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The project form — open and edit.
 *
 * **Not here at all:** phase and status. Each moves by its own act on the edit
 * page (advance, hold, resume), and the two that end a project belong to the
 * completion certificate and close-out. A dropdown would skip both.
 *
 * **Locked on edit:** the code, which is snapshotted onto ledger rows, and the
 * company.
 */
class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Project')
                ->columns(2)
                ->schema([
                    Select::make('organization_id')
                        ->label('Company')
                        ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => Organization::query()->count() === 1 ? (int) Organization::query()->value('id') : null)
                        ->required()
                        ->disabledOn('edit'),

                    TextInput::make('code')
                        ->label('Project code')
                        ->required()
                        ->maxLength(255)
                        ->disabledOn('edit')
                        ->helperText('Printed on every document and ledger row, so it cannot be changed once opened.'),

                    TextInput::make('name')->label('Project name')->required()->maxLength(255),

                    TextInput::make('client_name')->label('Client')->required()->maxLength(255),
                ]),
        ]);
    }
}
