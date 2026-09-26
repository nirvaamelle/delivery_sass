<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UsersResource;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->recordUrl(fn (User $record): string => UsersResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('role_names')
                    ->label('Roles')
                    ->badge()
                    ->state(fn (User $record): array => $record->getRoleNames()->all()),
            ]);
    }
}
