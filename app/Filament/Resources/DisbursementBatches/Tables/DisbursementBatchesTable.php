<?php

namespace App\Filament\Resources\DisbursementBatches\Tables;

use App\Domain\Hris\DisbursementMethod;
use App\Domain\Hris\DisbursementService;
use App\Models\DisbursementBatch;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Disbursement batches.
 *
 * **"Awaiting signature" is the column this screen exists for.** A cash batch is
 * not released until each payment is acknowledged, and the count of outstanding
 * ones is the list a treasurer works through. A screen that showed only the
 * batch total would make a half-paid batch look identical to a settled one.
 *
 * The file path is deliberately shown rather than linked: the file is on the
 * private disk, and a download link from a shared list is how every employee's
 * account number leaves the building.
 */
class DisbursementBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('prepared_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('run.number')->label('Register')->searchable(),

                TextColumn::make('method')
                    ->badge()
                    ->formatStateUsing(fn (DisbursementMethod $state): string => match ($state) {
                        DisbursementMethod::BankUpload => 'Bank upload',
                        DisbursementMethod::CashPayout => 'Cash payout',
                    })
                    ->color(fn (DisbursementMethod $state): string => $state === DisbursementMethod::BankUpload ? 'info' : 'warning'),

                TextColumn::make('total_amount')->label('Total')->money('PHP')->sortable(),

                TextColumn::make('items_count')->label('Payments')->counts('items'),

                /*
                 * The working number. Zero on a transmitted bank batch; on a cash
                 * batch it counts down as people sign, and the register does not
                 * become released until it reaches zero.
                 */
                TextColumn::make('outstanding')
                    ->label('Awaiting signature')
                    ->badge()
                    ->state(fn (DisbursementBatch $record): int => app(DisbursementService::class)->outstandingFor($record))
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success'),

                // The bank half's evidence.
                TextColumn::make('bank_reference')
                    ->label('Bank ref.')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('transmitted_at')->label('Transmitted')->dateTime()->placeholder('—'),

                // Shown, never linked — the file lives on the private disk.
                TextColumn::make('file_path')->label('File')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                /*
                 * The bank reference is what ties this batch to money that
                 * actually left the account. Without it a transmitted batch is a
                 * claim nobody can reconcile.
                 */
                Action::make('markTransmitted')
                    ->label('Mark transmitted')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (DisbursementBatch $record): bool => $record->transmitted_at === null)
                    ->schema([
                        TextInput::make('bank_reference')
                            ->label('Bank reference')
                            ->required()
                            ->maxLength(255)
                            ->helperText('The batch or transfer reference the bank gave back.'),
                    ])
                    ->action(fn (DisbursementBatch $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Batch transmitted.',
                        fn () => app(DisbursementService::class)->markTransmitted(
                            $record,
                            (string) $data['bank_reference'],
                            self::actingUser(),
                        ),
                    )),
            ])
            ->filters([
                SelectFilter::make('method')->options(
                    collect(DisbursementMethod::cases())
                        ->mapWithKeys(fn (DisbursementMethod $case): array => [$case->value => ucwords(str_replace('_', ' ', $case->value))])
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
