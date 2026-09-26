<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Models\Organization;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Warehouse')
                ->columns(2)
                ->schema([
                    Select::make('organization_id')
                        ->label('Company')
                        ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => Organization::query()->count() === 1 ? (int) Organization::query()->value('id') : null)
                        ->required(),
                    TextInput::make('code')
                        ->required()
                        ->maxLength(32)
                        ->helperText('Unique within the company. Appears on delivery receipts.'),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Textarea::make('address')
                        ->rows(3)
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('A closed site is deactivated, never deleted — history points here.'),
                ]),
        ]);
    }
}
