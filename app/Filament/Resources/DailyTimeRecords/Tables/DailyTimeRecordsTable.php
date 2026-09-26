<?php

namespace App\Filament\Resources\DailyTimeRecords\Tables;

use App\Domain\Hris\DtrStatus;
use App\Domain\Hris\TimekeepingService;
use App\Models\DailyTimeRecord;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The DTR, and the screen where the held-day mechanic becomes visible.
 *
 * **`Held` is styled as a state, not an error.** A held day is somebody's
 * unpaid work waiting on a certification, which is an ordinary thing on a busy
 * site — colouring it red would teach the timekeeper to treat a normal Tuesday
 * as a problem. What it must not be is invisible: the "Held, awaiting
 * certification" filter is the working list this screen exists for.
 */
class DailyTimeRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first. A held day that has been waiting three weeks is the
            // one somebody is not being paid for.
            ->defaultSort('work_date')
            ->columns([
                TextColumn::make('work_date')->label('Date')->date()->sortable(),
                TextColumn::make('employee.employee_number')->label('Employee')->searchable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('hours_worked')->label('Hours')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DtrStatus $state): string => ucfirst($state->value))
                    ->color(fn (DtrStatus $state): string => match ($state) {
                        DtrStatus::Validated, DtrStatus::Paid => 'success',
                        // Not danger: a held day is ordinary, and it is the
                        // rejected one that needs a second look.
                        DtrStatus::Held => 'warning',
                        DtrStatus::Rejected => 'danger',
                        DtrStatus::Unvalidated => 'gray',
                    }),

                /*
                 * Which cutoff the day fell out of. This is what a payslip reads
                 * to say "carried from", and having it on the screen is what lets
                 * a timekeeper answer "why is this May day on a June register".
                 */
                TextColumn::make('held_from_period_end')
                    ->label('Held from')
                    ->date()
                    ->placeholder('—'),

                TextColumn::make('paid_in_period_end')
                    ->label('Paid in')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                /*
                 * A day is certified by the site before payroll can pay it. The
                 * signature is the control: an imported day nobody looked at is
                 * a biometric reading, not a claim anybody stands behind.
                 *
                 * Rejecting needs a reason, because the timekeeper has to know
                 * what to correct — and the day is held, not deleted.
                 */
                Action::make('certifyDay')
                    ->label('Certify')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DailyTimeRecord $record): bool => $record->status === DtrStatus::Unvalidated)
                    ->schema([Textarea::make('remarks')->rows(2)])
                    ->action(function (DailyTimeRecord $record, array $data, Action $action): void {
                        $user = self::actingUser();

                        if ($user === null) {
                            Notification::make()->danger()->title('Sign in to certify a day.')->send();
                            $action->halt();

                            return;
                        }

                        self::attempt(
                            $action,
                            'Day certified.',
                            fn () => app(TimekeepingService::class)->validate(
                                $record,
                                $user,
                                filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                            ),
                        );
                    }),

                Action::make('rejectDay')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DailyTimeRecord $record): bool => $record->status === DtrStatus::Unvalidated)
                    ->schema([
                        Textarea::make('reason')
                            ->required()
                            ->rows(2)
                            ->helperText('What the timekeeper has to correct. The day is held, not deleted.'),
                    ])
                    ->action(function (DailyTimeRecord $record, array $data, Action $action): void {
                        $user = self::actingUser();

                        if ($user === null) {
                            Notification::make()->danger()->title('Sign in to reject a day.')->send();
                            $action->halt();

                            return;
                        }

                        self::attempt(
                            $action,
                            'Day rejected and held.',
                            fn () => app(TimekeepingService::class)->reject($record, $user, (string) $data['reason']),
                        );
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(DtrStatus::cases())
                        ->mapWithKeys(fn (DtrStatus $case): array => [$case->value => ucfirst($case->value)])
                        ->all()
                ),

                // The working list: days somebody worked and nobody has certified.
                Filter::make('held')
                    ->label('Held, awaiting certification')
                    ->query(fn (Builder $query): Builder => $query->where('status', DtrStatus::Held)),
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
