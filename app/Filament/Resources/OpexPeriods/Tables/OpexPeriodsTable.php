<?php

namespace App\Filament\Resources\OpexPeriods\Tables;

use App\Domain\Opex\BudgetActualService;
use App\Domain\Opex\OpexCalendarService;
use App\Domain\Opex\OpexStage;
use App\Models\CostCode;
use App\Models\OpexPeriod;
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
 * Slide 8's calendar, and where each month has got to.
 *
 * The column that earns its place is **Unexplained**: how many cost codes are
 * still blocking the close. A month that will not close and does not say why is
 * a month somebody reports as broken — the same reasoning as the payroll
 * register's own unexplained count.
 *
 * The stage is shown as a badge with its slide-8 day beside it, because "day 26"
 * is how the people running the calendar actually talk about it.
 */
class OpexPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('period_year', 'desc')
            ->columns([
                TextColumn::make('period')
                    ->label('Month')
                    ->state(fn (OpexPeriod $record): string => sprintf('%d-%02d', $record->period_year, $record->period_month))
                    ->sortable(['period_year', 'period_month']),

                TextColumn::make('organization.code')->label('Organization')->searchable(),

                TextColumn::make('stage')
                    ->badge()
                    ->formatStateUsing(fn (OpexStage $state): string => sprintf(
                        '%s%s',
                        ucfirst(str_replace('_', ' ', $state->value)),
                        $state->scheduledDay() === null ? '' : sprintf(' (day %d)', $state->scheduledDay()),
                    ))
                    ->color(fn (OpexStage $state): string => match ($state) {
                        OpexStage::Closed, OpexStage::Reporting => 'success',
                        OpexStage::BudgetReview => 'warning',
                        OpexStage::Capture => 'gray',
                        default => 'info',
                    }),

                /*
                 * Computed rather than stored, so it cannot disagree with the
                 * gate that actually refuses the close.
                 */
                TextColumn::make('unexplained')
                    ->label('Unexplained')
                    ->badge()
                    ->state(fn (OpexPeriod $record): int => count(app(BudgetActualService::class)->unexplained($record)))
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),

                TextColumn::make('cutoff_at')->label('Cut off')->dateTime()->placeholder('—'),
                TextColumn::make('closed_at')->label('Closed')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                /*
                 * Slide 8's month, one stage at a time. The stages are ordered
                 * and the service refuses a skip: closing a month whose costs
                 * were never coded produces a consolidation of whatever happened
                 * to be captured, and it looks exactly like a real one.
                 *
                 * An unexplained variance blocks the close. That is the whole
                 * point of the budget review stage, so the explanation is offered
                 * here rather than on a screen somebody has to go and find.
                 */
                Action::make('advanceStage')
                    ->label('Advance the stage')
                    ->icon('heroicon-o-forward')
                    ->visible(fn (OpexPeriod $record): bool => $record->stage !== OpexStage::Closed)
                    ->modalDescription('One stage at a time, in order. A month closed without coding its costs consolidates whatever happened to be captured.')
                    ->schema([
                        Select::make('stage')
                            ->label('Advance to')
                            ->required()
                            ->options(fn (OpexPeriod $record): array => collect(OpexStage::cases())
                                ->mapWithKeys(fn (OpexStage $stage): array => [$stage->value => ucfirst(str_replace('_', ' ', $stage->value))])
                                ->all()),

                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(fn (OpexPeriod $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Stage advanced.',
                        fn () => app(OpexCalendarService::class)->advanceTo(
                            $record,
                            OpexStage::from((string) $data['stage']),
                            self::actingUser(),
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        ),
                    )),

                Action::make('explainVariance')
                    ->label('Explain a variance')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('warning')
                    ->visible(fn (OpexPeriod $record): bool => app(BudgetActualService::class)->unexplained($record) !== [])
                    ->modalDescription('An unexplained variance blocks the close, so this is work with a deadline rather than a note.')
                    ->schema([
                        Select::make('cost_code_id')
                            ->label('Cost code')
                            ->required()
                            // unexplained() hands back cost code IDs, not rows.
                            ->options(fn (OpexPeriod $record): array => CostCode::query()
                                ->whereIn('id', app(BudgetActualService::class)->unexplained($record))
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => $code->code.' — '.$code->name])
                                ->all()),

                        Textarea::make('explanation')
                            ->required()
                            ->rows(3)
                            ->helperText('Why actual missed budget on that code. The next reviewer reads exactly this.'),
                    ])
                    ->action(function (OpexPeriod $record, array $data, Action $action): void {
                        $user = self::actingUser();
                        $costCode = CostCode::query()->find($data['cost_code_id']);

                        if ($user === null || $costCode === null) {
                            Notification::make()->danger()->title('Sign in, and choose a cost code that still exists.')->send();
                            $action->halt();

                            return;
                        }

                        self::attempt(
                            $action,
                            'Variance explained.',
                            fn () => app(BudgetActualService::class)->explain($record, $costCode, (string) $data['explanation'], $user),
                        );
                    }),
            ])
            ->filters([
                SelectFilter::make('stage')->options(
                    collect(OpexStage::cases())
                        ->mapWithKeys(fn (OpexStage $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
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
