<?php

namespace App\Filament\Resources\CloseOutChecklists\Tables;

use App\Domain\Closeout\CloseOutChecklistService;
use App\Filament\Resources\CloseOutChecklists\Pages\ViewCloseOutChecklist;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row per project being closed.
 *
 * The count of outstanding lines is the working content: it is what tells the
 * accountant which project can actually be closed this week. The report itself
 * is a click away, because "who cleared each item and when" is eleven rows and
 * does not belong in a cell.
 */
class CloseOutChecklistsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->recordUrl(fn ($record): string => ViewCloseOutChecklist::getUrl(['record' => $record]))
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('opened_at')->label('Opened')->date()->sortable(),

                TextColumn::make('items_count')->label('Lines')->counts('items'),

                TextColumn::make('outstanding')
                    ->label('Not cleared')
                    ->state(fn ($record): int => count(app(CloseOutChecklistService::class)->outstandingFor($record))),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn ($record): string => app(CloseOutChecklistService::class)->isComplete($record)
                        ? 'Complete'
                        : 'In progress')
                    ->color(fn (string $state): string => $state === 'Complete' ? 'success' : 'warning'),

                TextColumn::make('project.status')
                    ->label('Project')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst(is_string($state) ? $state : $state->value))
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('in_progress')
                    ->label('Not yet complete')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'items',
                        fn (Builder $items): Builder => $items->whereNull('cleared_at'),
                    )),
            ]);
    }
}
