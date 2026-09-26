<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Domain\Billing\BillingScheduleService;
use App\Models\BillingMilestone;
use App\Models\Contract;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

/**
 * The billing milestones built when the contract was signed, and the documents
 * each one needs before it can be billed.
 *
 * **F4 is the reason this screen exists.** A billing is refused while any
 * required document is missing, and until now there was no way to attach one:
 * the schedule existed and the first billing was unreachable.
 *
 * The milestones themselves are not editable. They come from the contract type's
 * template and their amounts are percentages of the contract sum — typing over
 * one would bill a percentage nobody agreed to.
 */
class MilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestones';

    protected static ?string $title = 'Billing milestones';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sequence')
            ->emptyStateHeading('No billing schedule yet')
            ->emptyStateDescription('The schedule is built when the contract is signed.')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->wrap(),
                TextColumn::make('percentage')->label('%')->suffix('%'),

                TextColumn::make('amount')
                    ->money('PHP')
                    ->state(fn (BillingMilestone $record): string => app(BillingScheduleService::class)->amountFor($record)),

                TextColumn::make('accomplishment_threshold')->label('Needs %')->suffix('%'),

                // Every missing document at once. A QS who clears one blocker
                // only to be shown the next, one round trip at a time, is how a
                // monthly cutoff gets missed.
                TextColumn::make('missing')
                    ->label('Still missing')
                    ->badge()
                    ->color('warning')
                    ->placeholder('Complete')
                    ->state(fn (BillingMilestone $record): array => app(BillingScheduleService::class)->missingFor($record)),
            ])
            ->recordActions([
                Action::make('attachDocument')
                    ->label('Attach document')
                    ->icon('heroicon-o-paper-clip')
                    ->modalDescription('Records that the document exists and where to find it. The file itself stays in the document register.')
                    ->schema([
                        Select::make('document_key')
                            ->label('Document')
                            ->required()
                            // Only what this milestone actually asks for: any
                            // other key would satisfy a count while the evidence
                            // stayed absent.
                            ->options(fn (BillingMilestone $record): array => $record->requirements()
                                ->pluck('document_key', 'document_key')
                                ->all()),

                        TextInput::make('reference')
                            ->required()
                            ->maxLength(255)
                            ->helperText('The document number, or where the signed copy is filed.'),

                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(function (BillingMilestone $record, array $data, Action $action): void {
                        try {
                            app(BillingScheduleService::class)->attachDocument(
                                $record,
                                (string) $data['document_key'],
                                (string) $data['reference'],
                                $this->actingUser(),
                                filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                            );
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title('Document recorded.')->send();
                    }),
            ]);
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function getOwner(): Contract
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Contract) {
            throw new LogicException('The milestones table has no contract.');
        }

        return $owner;
    }
}
