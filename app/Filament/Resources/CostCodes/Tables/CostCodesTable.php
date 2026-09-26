<?php

namespace App\Filament\Resources\CostCodes\Tables;

use App\Filament\Resources\CostCodes\CostCodesResource;
use App\Models\CostCode;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CostCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->recordUrl(fn (CostCode $record): string => CostCodesResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('wbs')
                    ->label('Path')
                    ->state(fn (CostCode $record): string => $record->wbsPath()),
                TextColumn::make('organization.name')->label('Company')->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
