<?php

namespace App\Filament\Resources\SalesInvoices\Tables;

use App\Domain\Billing\CollectionService;
use App\Domain\Billing\InvoiceStatus;
use App\Models\SalesInvoice;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class SalesInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_on', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('billing.number')->label('Billing')->searchable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('gross_amount')->label('Gross')->money('PHP')->sortable(),
                TextColumn::make('retention_amount')->label('Retention')->money('PHP'),

                TextColumn::make('withholding_amount')
                    ->label('Withheld')
                    ->money('PHP')
                    ->description(fn (SalesInvoice $record): ?string => $record->withholding_code),

                TextColumn::make('collectible_amount')->label('Collectible')->money('PHP')->sortable(),

                /*
                 * Outstanding is summed from the receipts rather than stored, so
                 * this screen and the AR sweep cannot disagree about whether a
                 * client owes money — which is the one disagreement nobody
                 * notices until a payment is chased twice.
                 */
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->money('PHP')
                    ->state(fn (SalesInvoice $record): string => app(CollectionService::class)->outstandingFor($record)),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (InvoiceStatus $state): string => match ($state) {
                        InvoiceStatus::Collected => 'success',
                        InvoiceStatus::PartlyCollected => 'warning',
                        InvoiceStatus::Issued => 'info',
                        InvoiceStatus::Cancelled => 'gray',
                    }),

                TextColumn::make('issued_on')->label('Issued')->date()->sortable(),
            ])
            ->recordActions([
                /*
                 * The official receipt. Over-collection is refused by the service
                 * — it is almost never generosity, it is a payment belonging to
                 * another invoice, and it hides there until a reconciliation
                 * finds it.
                 */
                Action::make('recordCollection')
                    ->label('Record collection')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (SalesInvoice $record): bool => bccomp(
                        app(CollectionService::class)->outstandingFor($record), '0', 4
                    ) > 0)
                    ->schema([
                        TextInput::make('amount')
                            ->required()
                            ->rules(['numeric', 'gt:0'])
                            ->helperText(fn (SalesInvoice $record): string => 'Outstanding: '
                                .number_format((float) app(CollectionService::class)->outstandingFor($record), 2)),

                        DatePicker::make('received_on')->label('Received on')->required()->default(now()),

                        TextInput::make('payment_reference')
                            ->label('Payment reference')
                            ->maxLength(255)
                            ->helperText('The cheque or transfer reference on the client\'s remittance.'),

                        TextInput::make('certificate_reference')
                            ->label('Withholding certificate')
                            ->maxLength(255)
                            ->helperText('BIR 2307 reference, when the client withheld tax at source.'),

                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(fn (SalesInvoice $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Collection recorded.',
                        fn () => app(CollectionService::class)->collect(
                            $record,
                            (string) $data['amount'],
                            Carbon::parse($data['received_on']),
                            filled($data['payment_reference'] ?? null) ? (string) $data['payment_reference'] : null,
                            filled($data['certificate_reference'] ?? null) ? (string) $data['certificate_reference'] : null,
                            self::actingUser(),
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        ),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(InvoiceStatus::cases())
                        ->mapWithKeys(fn (InvoiceStatus $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
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

    private static function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
