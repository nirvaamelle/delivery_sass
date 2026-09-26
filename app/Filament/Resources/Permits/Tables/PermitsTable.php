<?php

namespace App\Filament\Resources\Permits\Tables;

use App\Domain\Mobilization\PermitService;
use App\Domain\Mobilization\PermitType;
use App\Models\Permit;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PermitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Soonest to expire first. The list exists to be acted on, and the
            // permit about to lapse is the only row that needs acting on today.
            ->defaultSort('expires_on')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (PermitType $state): string => ucwords(str_replace('_', ' ', $state->value))),

                TextColumn::make('issuing_authority')->label('Issued by')->searchable(),

                /*
                 * Computed, never stored — the same rule the vendor register
                 * follows for RFQ eligibility. A cached flag is only as true as
                 * the last job that ran, and the day it fails quietly is the day
                 * a site mobilizes on a lapsed permit.
                 */
                IconColumn::make('in_force')
                    ->label('In force')
                    ->boolean()
                    ->state(fn (Permit $record): bool => app(PermitService::class)->isValid($record)),

                TextColumn::make('expires_on')
                    ->label('Expires')
                    ->date()
                    ->sortable()
                    ->color(fn (Permit $record): string => app(PermitService::class)->isValid($record) ? 'gray' : 'danger'),
            ])
            ->filters([
                SelectFilter::make('type')->options(
                    collect(PermitType::cases())
                        ->mapWithKeys(fn (PermitType $case): array => [$case->value => ucwords(str_replace('_', ' ', $case->value))])
                        ->all()
                ),
            ]);
    }
}
