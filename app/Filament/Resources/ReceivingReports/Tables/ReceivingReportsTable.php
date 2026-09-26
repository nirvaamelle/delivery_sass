<?php

namespace App\Filament\Resources\ReceivingReports\Tables;

use App\Domain\Procurement\InspectionService;
use App\Domain\Procurement\InspectionVerdict;
use App\Domain\Procurement\StockService;
use App\Models\ReceivingReport;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

class ReceivingReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('delivery_receipt_number')->label('DR no.')->searchable(),
                TextColumn::make('purchaseOrder.number')->label('PO')->searchable(),
                TextColumn::make('received_at')->label('Received')->dateTime()->sortable(),

                /*
                 * The exit gate asks for a short delivery "noted on the DR", so
                 * it is a column and not something a reader has to derive by
                 * comparing two other screens.
                 */
                IconColumn::make('has_shortfall')
                    ->label('Short')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('success'),

                TextColumn::make('inspection_verdict')
                    ->label('Inspection')
                    ->badge()
                    // The column reads "uninspected" rather than blank when
                    // there is no verdict, because a delivery nobody has looked
                    // at is a state worth seeing, not an absence.
                    ->state(function (ReceivingReport $record): string {
                        $verdict = $record->inspection()->value('verdict');

                        return $verdict instanceof InspectionVerdict
                            ? $verdict->value
                            : 'uninspected';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        InspectionVerdict::Passed->value => 'success',
                        InspectionVerdict::PartiallyRejected->value => 'warning',
                        InspectionVerdict::Rejected->value => 'danger',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                /*
                 * Inspection is the control the three-way match depends on: what
                 * was ACCEPTED, not what arrived, is what the company owes for.
                 * Until this existed the payables screens could not be reached
                 * in a clean system at all.
                 *
                 * A rejection needs a reason, because "100 bags rejected" with no
                 * cause is a number nobody can act on — least of all the vendor
                 * being asked to take them back.
                 */
                Action::make('inspectDelivery')
                    ->label('Inspect')
                    ->icon('heroicon-o-magnifying-glass')
                    ->visible(fn (ReceivingReport $record): bool => $record->inspection === null)
                    ->modalWidth('3xl')
                    ->modalDescription('Accepted plus rejected must equal what was received on each line — a quantity that vanishes between the two is the discrepancy this refuses.')
                    ->schema([
                        Repeater::make('lines')
                            ->label('Line by line')
                            ->default(fn (ReceivingReport $record): array => $record->lines
                                ->map(fn ($line): array => [
                                    'receiving_report_line_id' => $line->getKey(),
                                    'quantity_accepted' => (string) $line->quantity_received,
                                    'quantity_rejected' => '0',
                                    'rejection_reason' => null,
                                ])
                                ->all())
                            ->schema([
                                TextInput::make('receiving_report_line_id')->label('Line')->disabled()->dehydrated(),
                                TextInput::make('quantity_accepted')->label('Accepted')->required()->rules(['numeric', 'gte:0']),
                                TextInput::make('quantity_rejected')->label('Rejected')->required()->rules(['numeric', 'gte:0']),
                                TextInput::make('rejection_reason')->label('Reason if rejected')->maxLength(255),
                            ])
                            ->columns(4)
                            ->addable(false)
                            ->deletable(false),

                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(function (ReceivingReport $record, array $data, Action $action): void {
                        $lines = array_map(fn (array $line): array => [
                            'receiving_report_line_id' => (int) $line['receiving_report_line_id'],
                            'quantity_accepted' => (string) $line['quantity_accepted'],
                            'quantity_rejected' => (string) $line['quantity_rejected'],
                            'rejection_reason' => (string) ($line['rejection_reason'] ?? ''),
                        ], $data['lines'] ?? []);

                        try {
                            app(InspectionService::class)->inspect(
                                $record,
                                $lines,
                                self::actingUser(),
                                filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                            );
                        } catch (DomainException|InvalidArgumentException $e) {
                            Notification::make()->danger()->title('The inspection was refused')->body($e->getMessage())->persistent()->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title('Inspected.')->send();
                    }),

                Action::make('returnToVendor')
                    ->label('Return to vendor')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (ReceivingReport $record): bool => $record->inspection !== null
                        && $record->inspection->returnToVendor === null)
                    ->modalDescription('Raises the RTV slip for everything the inspection rejected.')
                    ->schema([Textarea::make('remarks')->rows(2)])
                    ->action(fn (ReceivingReport $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Return to vendor raised.',
                        fn () => app(InspectionService::class)->returnToVendor(
                            $record->inspection,
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        ),
                    )),

                Action::make('receiveIntoStock')
                    ->label('Receive into stock')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('success')
                    ->visible(fn (ReceivingReport $record): bool => $record->inspection !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Takes the ACCEPTED quantity onto the stock card at its purchase price. Rejected goods never enter stock.')
                    ->action(fn (ReceivingReport $record, Action $action) => self::attempt(
                        $action,
                        'Received into stock.',
                        fn () => app(StockService::class)->receive($record, self::actingUser()),
                    )),
            ])
            ->filters([
                TernaryFilter::make('has_shortfall')->label('Short delivery'),
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

    private static function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
