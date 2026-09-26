<?php

namespace App\Filament\Resources\Contracts\Tables;

use App\Domain\Contracts\ContractStatus;
use App\Filament\Resources\Contracts\ContractsResource;
use App\Models\Contract;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContractsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (Contract $record): string => ContractsResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable()->sortable(),
                TextColumn::make('contract_sum')->label('Sum')->money('PHP')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ContractStatus $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->color(fn (ContractStatus $state): string => match ($state) {
                        ContractStatus::Signed => 'success',
                        ContractStatus::ForSignature => 'warning',
                        ContractStatus::Terminated => 'danger',
                        ContractStatus::Draft => 'gray',
                    }),

                // A percentage on screen, a fraction in the column. Shown because
                // it is what every invoice withholds.
                TextColumn::make('retention_rate')
                    ->label('Retention')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : rtrim(rtrim(bcmul($state, '100', 4), '0'), '.').'%'),

                TextColumn::make('signed_at')->label('Signed')->date()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(ContractStatus::cases())
                        ->mapWithKeys(fn (ContractStatus $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
                        ->all()
                ),
            ]);
    }
}
