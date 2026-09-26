<?php

namespace App\Filament\Resources\Mobilizations\Tables;

use App\Domain\Mobilization\MobilizationService;
use App\Domain\Mobilization\MobilizationStatus;
use App\Models\Mobilization;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MobilizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('mobilized_on', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                // The countersigned order this mobilized against — F2's gate,
                // visible rather than merely enforced.
                TextColumn::make('purchaseOrder.number')->label('Against PO')->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (MobilizationStatus $state): string => match ($state) {
                        MobilizationStatus::Completed => 'success',
                        MobilizationStatus::InProgress => 'info',
                        MobilizationStatus::Cancelled => 'gray',
                    }),

                /*
                 * What is still outstanding, listed rather than counted. A count
                 * tells a site manager there is work left; the names tell them
                 * what to go and do.
                 */
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->state(fn (Mobilization $record): string => implode(', ', app(MobilizationService::class)->outstandingFor($record)) ?: '—')
                    ->wrap(),

                TextColumn::make('mobilized_on')->label('Mobilized')->date()->sortable(),
            ]);
    }
}
