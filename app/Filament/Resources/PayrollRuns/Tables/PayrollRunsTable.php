<?php

namespace App\Filament\Resources\PayrollRuns\Tables;

use App\Domain\Hris\DisbursementMethod;
use App\Domain\Hris\DisbursementService;
use App\Domain\Hris\PayrollRunStatus;
use App\Domain\Hris\PayrollService;
use App\Domain\Hris\PayrollVarianceService;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The payroll register.
 *
 * **Totals only — never a line.** A run total across everybody reveals nobody's
 * pay, which is why `payroll_runs` keeps its totals in plain DECIMAL while every
 * `payroll_lines` amount is encrypted. Putting a per-employee figure on this
 * screen would undo that in the one place it is easiest to read.
 *
 * The column that earns its place is **Unexplained**: how many projects are
 * still blocking approval under F10. A register that cannot be approved and does
 * not say why is a register somebody reports as broken.
 */
class PayrollRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),

                TextColumn::make('period')
                    ->label('Cutoff')
                    ->state(fn (PayrollRun $record): string => sprintf(
                        '%s to %s',
                        $record->period_start->toDateString(),
                        $record->period_end->toDateString(),
                    )),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PayrollRunStatus $state): string => ucfirst($state->value))
                    ->color(fn (PayrollRunStatus $state): string => match ($state) {
                        PayrollRunStatus::Released => 'success',
                        PayrollRunStatus::Approved => 'info',
                        PayrollRunStatus::Computed => 'warning',
                        PayrollRunStatus::Draft, PayrollRunStatus::Queued, PayrollRunStatus::Cancelled => 'gray',
                    }),

                TextColumn::make('lines_count')
                    ->label('Employees')
                    ->counts('lines'),

                TextColumn::make('gross_total')->label('Gross')->money('PHP')->sortable(),
                TextColumn::make('net_total')->label('Net')->money('PHP')->sortable(),

                /*
                 * F10, on the screen. Computed rather than stored, so it cannot
                 * disagree with the gate that actually refuses the approval.
                 */
                TextColumn::make('unexplained')
                    ->label('Unexplained')
                    ->badge()
                    ->state(fn (PayrollRun $record): int => count(app(PayrollVarianceService::class)->unexplained($record)))
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),

                /*
                 * Employees the run could not price. Shown as a count so a clerk
                 * can see there IS an exception; the reasons are on the run, not
                 * on a shared list, because they name people.
                 */
                TextColumn::make('exception_count')
                    ->label('Exceptions')
                    ->state(fn (PayrollRun $record): int => count($record->exceptions ?? []))
                    ->toggleable(),

                TextColumn::make('posted_at')
                    ->label('In ledger')
                    ->dateTime()
                    ->placeholder('Not posted')
                    ->toggleable(),
            ])
            ->recordActions([
                /*
                 * Slide 7's steps, each its own act because each is a different
                 * person: the clerk computes, the manager explains what moved,
                 * the officer approves, and only then does money leave.
                 *
                 * Approval is gated on F10 — no unexplained per-project variance
                 * against the last cutoff. A register that jumped 40% with no
                 * reason is the one nobody should sign.
                 */
                Action::make('computeRun')
                    ->label('Compute')
                    ->icon('heroicon-o-calculator')
                    ->visible(fn (PayrollRun $record): bool => in_array(
                        $record->status,
                        [PayrollRunStatus::Draft, PayrollRunStatus::Queued],
                        true,
                    ))
                    ->requiresConfirmation()
                    ->modalDescription('Prices every certified day in the cutoff, and holds anything that cannot be paid — with the reason on the register.')
                    ->action(fn (PayrollRun $record, Action $action) => self::attempt(
                        $action,
                        'Register computed.',
                        fn () => app(PayrollService::class)->compute($record, self::actingUser()),
                    )),

                Action::make('explainVariance')
                    ->label('Explain a variance')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->visible(fn (PayrollRun $record): bool => $record->status === PayrollRunStatus::Computed
                        && app(PayrollVarianceService::class)->unexplained($record) !== [])
                    ->modalDescription('An unexplained movement against the last cutoff blocks approval. Say what changed on site.')
                    ->schema([
                        Select::make('project_id')
                            ->label('Project')
                            ->required()
                            // unexplained() hands back project IDs, not rows. Read
                            // past the scope: the officer approving a register is
                            // often not assigned to every project on it.
                            ->options(fn (PayrollRun $record): array => Project::withoutProjectScope(
                                fn (): array => Project::query()
                                    ->whereIn('id', app(PayrollVarianceService::class)->unexplained($record))
                                    ->orderBy('code')
                                    ->pluck('code', 'id')
                                    ->all(),
                            )),

                        Textarea::make('explanation')
                            ->required()
                            ->rows(3)
                            ->helperText('What happened on that project — more crew, overtime authorised, a demobilisation.'),
                    ])
                    ->action(function (PayrollRun $record, array $data, Action $action): void {
                        $user = self::actingUser();

                        if ($user === null) {
                            Notification::make()->danger()->title('Sign in to explain a variance.')->send();
                            $action->halt();

                            return;
                        }

                        $project = Project::withoutProjectScope(
                            fn (): ?Project => Project::query()->find($data['project_id']),
                        );

                        if ($project === null) {
                            Notification::make()->danger()->title('That project no longer exists.')->send();
                            $action->halt();

                            return;
                        }

                        self::attempt(
                            $action,
                            'Variance explained.',
                            fn () => app(PayrollVarianceService::class)->explain(
                                $record,
                                $project,
                                (string) $data['explanation'],
                                $user,
                            ),
                        );
                    }),

                Action::make('approveRun')
                    ->label('Approve register')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (PayrollRun $record): bool => $record->status === PayrollRunStatus::Computed)
                    ->requiresConfirmation()
                    ->modalDescription('Approval is what marks the days paid. A computed register can still change; an approved one cannot.')
                    ->action(function (PayrollRun $record, Action $action): void {
                        $user = self::actingUser();

                        if ($user === null) {
                            Notification::make()->danger()->title('Sign in to approve a register.')->send();
                            $action->halt();

                            return;
                        }

                        self::attempt(
                            $action,
                            'Register approved.',
                            fn () => app(PayrollService::class)->approve($record, $user),
                        );
                    }),

                Action::make('prepareDisbursement')
                    ->label('Prepare payment')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn (PayrollRun $record): bool => in_array(
                        $record->status,
                        [PayrollRunStatus::Approved, PayrollRunStatus::Released],
                        true,
                    ) && $record->disbursementBatch === null)
                    ->schema([
                        Select::make('method')
                            ->label('How it is paid')
                            ->required()
                            ->options(collect(DisbursementMethod::cases())
                                ->mapWithKeys(fn (DisbursementMethod $m): array => [$m->value => ucfirst(str_replace('_', ' ', $m->value))])
                                ->all()),
                    ])
                    ->action(fn (PayrollRun $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Disbursement batch prepared.',
                        fn () => app(DisbursementService::class)->prepare(
                            $record,
                            DisbursementMethod::from((string) $data['method']),
                            self::actingUser(),
                        ),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(PayrollRunStatus::cases())
                        ->mapWithKeys(fn (PayrollRunStatus $case): array => [$case->value => ucfirst($case->value)])
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
