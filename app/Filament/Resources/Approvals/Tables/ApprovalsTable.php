<?php

namespace App\Filament\Resources\Approvals\Tables;

use App\Domain\Approvals\ApprovalDecisions;
use App\Models\Approval;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApprovalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first. An approvals queue sorted newest-first quietly
            // starves the oldest item, which is the one already closest to
            // missing the deck's cycle-time target.
            ->defaultSort('created_at', 'asc')
            ->emptyStateHeading('Nothing waiting on you')
            ->emptyStateDescription('Approvals addressed to your roles appear here as documents are submitted.')
            ->columns([
                TextColumn::make('document_number')
                    ->label('Document')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->searchable(),

                TextColumn::make('tier')
                    ->sortable(),

                TextColumn::make('step')
                    ->label('Step'),

                TextColumn::make('approver_role')
                    ->label('Awaiting')
                    ->badge(),

                IconColumn::make('sole_source')
                    ->label('Sole source')
                    // Worth its own column: a sole-source purchase is here
                    // because it escalated one tier, and the approver should
                    // know that before signing rather than after.
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedExclamationTriangle)
                    ->falseIcon(Heroicon::OutlinedMinusSmall),

                TextColumn::make('created_at')
                    ->label('Waiting since')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->schema([
                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->helperText('Optional. Recorded against your signature.'),
                    ])
                    ->action(function (Approval $record, array $data): void {
                        // Through the document's own service (ApprovalDecisions),
                        // so the signature and the status change land together;
                        // the router it calls re-checks that the
                        // signer actually holds the tier's role. The inbox
                        // query already filters by role, but a filtered list is
                        // a convenience, not an authorisation check.
                        app(ApprovalDecisions::class)->approve(
                            $record,
                            auth()->user(),
                            $data['remarks'] ?: null,
                        );
                    }),

                Action::make('return')
                    ->label('Return')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason for return')
                            // Required, not optional. The reason is what tells
                            // the originator what to fix, and in the billing
                            // chain it is what stops the same deducted item
                            // reappearing on the next submission.
                            ->required()
                            ->minLength(3),
                    ])
                    ->action(function (Approval $record, array $data): void {
                        app(ApprovalDecisions::class)->returnForRevision(
                            $record,
                            auth()->user(),
                            $data['reason'],
                        );
                    }),
            ]);
    }
}
