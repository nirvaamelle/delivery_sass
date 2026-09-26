<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domain\Access\UserAccountService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')->email()->required()->maxLength(255),

                    // Create only. Afterwards a password is replaced by the
                    // "Set password" action, never shown or pre-filled.
                    TextInput::make('password')
                        ->password()
                        ->revealable(false)
                        ->required()
                        ->visibleOn('create')
                        ->helperText(sprintf('At least %d characters.', UserAccountService::MIN_PASSWORD_LENGTH)),
                ]),

            Section::make('Roles')
                ->description('What this account can open. Which projects it sees is set under Project assignments below.')
                ->schema([
                    CheckboxList::make('roles')
                        ->hiddenLabel()
                        ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                        ->columns(3),
                ]),
        ]);
    }
}
