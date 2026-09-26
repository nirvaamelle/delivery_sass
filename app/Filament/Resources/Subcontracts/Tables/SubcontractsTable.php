<?php

namespace App\Filament\Resources\Subcontracts\Tables;

use App\Domain\Closeout\BackChargeService;
use App\Domain\Procurement\SubcontractStatus;
use App\Models\Subcontract;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubcontractsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No subcontracts awarded')
            ->emptyStateDescription('Back-charges and the final account both resolve against a subcontract.')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('vendor.name')->label('Subcontractor')->searchable(),
                TextColumn::make('scope_of_work')->label('Scope')->limit(40)->wrap(),
                TextColumn::make('contract_amount')->label('Amount')->money('PHP')->sortable(),

                // What F6 exists for: the amount is not what is payable once
                // back-charges are netted off.
                TextColumn::make('net_payable')
                    ->label('Net payable')
                    ->money('PHP')
                    ->state(fn (Subcontract $record): string => app(BackChargeService::class)->netPayable($record)),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SubcontractStatus $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->color(fn (SubcontractStatus $state): string => match ($state) {
                        SubcontractStatus::Completed => 'success',
                        SubcontractStatus::Terminated => 'danger',
                        SubcontractStatus::InProgress, SubcontractStatus::Mobilized => 'info',
                        SubcontractStatus::Awarded => 'gray',
                    }),

                TextColumn::make('works_start')->label('Start')->date()->toggleable(),
                TextColumn::make('works_end')->label('End')->date()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(SubcontractStatus::cases())
                        ->mapWithKeys(fn (SubcontractStatus $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
                        ->all()
                ),
            ]);
    }
}
