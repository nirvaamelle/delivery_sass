<?php

namespace App\Filament\Resources\ApVouchers\Tables;

use App\Domain\Procurement\ApVoucherStatus;
use App\Domain\Procurement\PayablesService;
use App\Models\ApVoucher;
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

class ApVouchersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('raised_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('vendor.name')->label('Vendor')->searchable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                // The match is on the row because a voucher without one cannot
                // exist — the column makes the control visible rather than
                // merely enforced.
                TextColumn::make('match.number')->label('Match')->searchable(),

                TextColumn::make('gross_amount')->label('Gross')->money('PHP')->sortable(),

                TextColumn::make('withholding_amount')
                    ->label('Withheld')
                    ->money('PHP')
                    ->description(fn ($record): ?string => $record->withholding_code),

                TextColumn::make('advance_offset')->label('Advance')->money('PHP'),

                TextColumn::make('net_amount')->label('Net payable')->money('PHP')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ApVoucherStatus $state): string => match ($state) {
                        ApVoucherStatus::Paid => 'success',
                        ApVoucherStatus::Approved => 'info',
                        ApVoucherStatus::Cancelled => 'danger',
                        ApVoucherStatus::Submitted, ApVoucherStatus::Raised => 'warning',
                    }),

                // Blank until paid. What an auditor traces from the statement
                // back to the voucher.
                TextColumn::make('payment_reference')
                    ->label('Payment')
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (ApVoucher $record): ?string => $record->paid_at?->toDateString()),
            ])
            ->recordActions([
                /*
                 * The voucher's life, one act at a time. Each is offered only in
                 * the state it belongs to, and each goes through PayablesService
                 * — the screen never writes a status itself.
                 */
                Action::make('submitVoucher')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (ApVoucher $record): bool => $record->status === ApVoucherStatus::Raised)
                    ->requiresConfirmation()
                    ->modalDescription('Routes the voucher by its NET amount — what the company actually pays.')
                    ->action(fn (ApVoucher $record, Action $action) => self::attempt(
                        $action,
                        'Submitted for approval.',
                        fn () => app(PayablesService::class)->submit($record, self::actingUser()),
                    )),

                Action::make('payVoucher')
                    ->label('Record payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (ApVoucher $record): bool => $record->status === ApVoucherStatus::Approved)
                    ->modalDescription('Records what the bank statement will be reconciled against. A payment cannot be undone — a vendor credit note corrects it.')
                    ->schema([
                        TextInput::make('payment_reference')
                            ->label('Bank reference')
                            ->required()
                            ->maxLength(255)
                            ->helperText('The cheque number, transfer reference or batch number.'),

                        DatePicker::make('paid_at')->label('Date paid')->required()->default(now()),
                    ])
                    ->action(fn (ApVoucher $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Payment recorded.',
                        fn () => app(PayablesService::class)->pay(
                            $record,
                            (string) $data['payment_reference'],
                            Carbon::parse($data['paid_at']),
                            self::actingUser(),
                        ),
                    )),

                Action::make('cancelVoucher')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ApVoucher $record): bool => ! in_array($record->status, [ApVoucherStatus::Paid, ApVoucherStatus::Cancelled], true))
                    ->modalDescription('Any advance this voucher recovered goes back to the vendor\'s outstanding balance.')
                    ->schema([
                        Textarea::make('reason')->required()->rows(2),
                    ])
                    ->action(fn (ApVoucher $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Voucher cancelled.',
                        fn () => app(PayablesService::class)->cancel($record, (string) $data['reason'], self::actingUser()),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(ApVoucherStatus::cases())
                        ->mapWithKeys(fn (ApVoucherStatus $case): array => [$case->value => ucfirst($case->value)])
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
