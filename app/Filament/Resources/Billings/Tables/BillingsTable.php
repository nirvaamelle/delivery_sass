<?php

namespace App\Filament\Resources\Billings\Tables;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class BillingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('milestone.name')->label('Milestone')->searchable(),

                /*
                 * Returned is styled as a state rather than as a failure, because
                 * it IS one: PHASE-PLAN.md calls the returned branch a
                 * first-class path. Colouring it like an error would teach the
                 * QS to treat an ordinary re-measurement as something gone wrong.
                 */
                TextColumn::make('status')
                    ->badge()
                    // The enum's value is snake_case for the database; a badge is
                    // read by a person.
                    ->formatStateUsing(fn (BillingStatus $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->color(fn (BillingStatus $state): string => match ($state) {
                        BillingStatus::Approved => 'success',
                        BillingStatus::Submitted => 'info',
                        BillingStatus::Returned => 'warning',
                        BillingStatus::Draft, BillingStatus::Cancelled => 'gray',
                    }),

                TextColumn::make('gross_amount')->label('Gross')->money('PHP')->sortable(),
                TextColumn::make('retention_amount')->label('Retention')->money('PHP'),
                TextColumn::make('net_amount')->label('Net')->money('PHP')->sortable(),

                // The deducted-line count, computed. It is the number that says
                // whether a resubmission is going to be refused.
                TextColumn::make('deducted_lines')
                    ->label('Deducted')
                    ->state(fn (Billing $record): int => $record->lines()->where('deducted', true)->count()),

                TextColumn::make('returned_reason')->label('Returned because')->limit(40)->toggleable(),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime()->sortable(),
            ])
            ->recordActions([
                /*
                 * Slide 6's two branches, and they are not symmetrical. Approval
                 * is a date and a remark. A return needs the REASON — it is what
                 * tells the QS what to re-measure — and it may name the lines the
                 * client struck out, which are then blocked from reappearing on
                 * the next submission until somebody clears them deliberately.
                 */
                Action::make('approveBilling')
                    ->label('Client approved')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Billing $record): bool => $record->status === BillingStatus::Submitted)
                    ->schema([
                        DatePicker::make('evaluated_at')->label('Date evaluated')->required()->default(now()),
                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(fn (Billing $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Billing approved.',
                        fn () => app(BillingService::class)->approve(
                            $record,
                            Carbon::parse($data['evaluated_at']),
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        ),
                    )),

                Action::make('returnBilling')
                    ->label('Returned for re-measurement')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Billing $record): bool => $record->status === BillingStatus::Submitted)
                    ->modalWidth('2xl')
                    ->schema([
                        DatePicker::make('evaluated_at')->label('Date evaluated')->required()->default(now()),

                        Textarea::make('reason')
                            ->required()
                            ->rows(2)
                            ->helperText('What the QS has to re-measure. Without it the returned branch is just a rejection.'),

                        Repeater::make('deductions')
                            ->label('Lines the client struck out')
                            ->defaultItems(0)
                            ->addActionLabel('Add a deducted line')
                            ->schema([
                                TextInput::make('line_key')->label('Line key')->required(),
                                TextInput::make('reason')->required(),
                            ])
                            ->columns(2)
                            ->helperText('A deducted line is blocked from the next submission until it is cleared.'),
                    ])
                    ->action(fn (Billing $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Billing returned.',
                        fn () => app(BillingService::class)->returnForRemeasurement(
                            $record,
                            Carbon::parse($data['evaluated_at']),
                            (string) $data['reason'],
                            array_map(fn (array $line): array => [
                                'line_key' => (string) $line['line_key'],
                                'reason' => (string) $line['reason'],
                            ], $data['deductions'] ?? []),
                        ),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(BillingStatus::cases())
                        ->mapWithKeys(fn (BillingStatus $case): array => [$case->value => ucfirst($case->value)])
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
