<?php

namespace App\Filament\Resources\PurchaseRequisitions\Tables;

use App\Domain\Requisitions\RequisitionService;
use App\Domain\Requisitions\RequisitionStatus;
use App\Models\PurchaseRequisition;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseRequisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first: a queue sorted newest-first starves the oldest item,
            // which is the one already closest to missing the cycle-time target.
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),

                TextColumn::make('project.code')
                    ->label('Project')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (RequisitionStatus $state): string => match ($state) {
                        RequisitionStatus::Approved => 'success',
                        RequisitionStatus::Submitted => 'warning',
                        RequisitionStatus::Returned => 'danger',
                        RequisitionStatus::Draft, RequisitionStatus::Cancelled => 'gray',
                    }),

                TextColumn::make('total_amount')
                    ->label('Amount')
                    ->money('PHP')
                    ->sortable(),

                TextColumn::make('submitted_at')->label('Submitted')->dateTime()->sortable(),

                TextColumn::make('created_at')->label('Raised')->date()->sortable(),
            ])
            ->recordActions([
                /*
                 * Submitting routes the requisition through the authority matrix
                 * and commits its budget. Approving and returning happen in the
                 * one approvals inbox, not here — seven inboxes is what the
                 * polymorphic approvals table exists to avoid.
                 */
                Action::make('submitRequisition')
                    ->label('Submit for approval')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (PurchaseRequisition $record): bool => $record->status === RequisitionStatus::Draft)
                    ->requiresConfirmation()
                    ->modalDescription('Commits the budget against each cost code and sends the requisition to its approvers.')
                    ->action(fn (PurchaseRequisition $record, Action $action) => self::attempt(
                        $action,
                        'Submitted for approval.',
                        fn () => app(RequisitionService::class)->submit($record),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(RequisitionStatus::cases())
                        ->mapWithKeys(fn (RequisitionStatus $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
                        ->all()
                ),
            ]);
    }

    /**
     * Run one act, and put a refusal on screen rather than in a stack trace.
     */
    private static function attempt(Action $action, string $success, callable $act): void
    {
        try {
            $act();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            $action->halt();
        }

        Notification::make()->success()->title($success)->send();
    }
}
