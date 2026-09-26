<?php

namespace App\Filament\Resources\CostCodes\Schemas;

use App\Models\CostCode;
use App\Models\Organization;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CostCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cost code')
                ->columns(2)
                ->schema([
                    Select::make('organization_id')
                        ->label('Company')
                        ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => Organization::query()->count() === 1 ? (int) Organization::query()->value('id') : null)
                        ->required()
                        ->live()
                        ->disabledOn('edit'),

                    TextInput::make('code')
                        ->required()
                        ->maxLength(255)
                        ->disabledOn('edit')
                        ->helperText('Budgets and reports roll up by this, so it cannot be changed once added.'),

                    TextInput::make('name')->required()->maxLength(255),

                    Select::make('parent_id')
                        ->label('Parent')
                        ->placeholder('None — a top-level code')
                        ->searchable()
                        ->options(fn (Get $get, ?CostCode $record): array => CostCode::query()
                            ->where('organization_id', $record !== null ? $record->organization_id : $get('organization_id'))
                            ->when($record !== null, fn ($query) => $query->whereKeyNot($record?->getKey()))
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => $code->code.' — '.$code->name])
                            ->all()),
                ]),
        ]);
    }
}
