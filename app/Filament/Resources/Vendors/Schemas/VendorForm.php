<?php

namespace App\Filament\Resources\Vendors\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The vendor register form.
 *
 * Standing is deliberately absent. Status is changed by the table's actions,
 * which call VendorService — a status field here would let someone type a
 * removed vendor back into good standing, which PLAN.md §5 forbids.
 */
class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Carried on every purchase order and voucher for this vendor.'),

                        TextInput::make('name')->required()->maxLength(255),

                        TextInput::make('tin')
                            ->label('TIN')
                            ->maxLength(255)
                            ->helperText('Needed before withholding tax can be reported against payments.'),
                    ]),

                Section::make('Contact')
                    ->columns(2)
                    ->schema([
                        TextInput::make('contact_person')->maxLength(255),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(255),
                        Textarea::make('address')->columnSpanFull(),
                    ]),

                Section::make('Bank details')
                    ->description('Encrypted at rest. Existing values are never displayed — leave blank to keep them.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('bank_name')->maxLength(255),
                        TextInput::make('bank_account_name')->maxLength(255),

                        // Write-only, on purpose. PLAN.md §3 encrypts this
                        // column; a form that decrypts and renders it hands back
                        // exactly what the encryption was for, on a panel every
                        // foreman can open.
                        TextInput::make('bank_account_number')
                            ->password()
                            ->revealable(false)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(null)),
                    ]),
            ]);
    }
}
