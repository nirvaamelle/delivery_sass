<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Domain\Opex\ExpenseService;
use App\Domain\Opex\ExpenseStatus;
use App\Models\Expense;
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
 * Site expenses, and the ones that came back.
 *
 * **`Returned` is styled as a state, not an error**, the same judgement the
 * billing screen makes about a returned billing. Most returns are a missing
 * receipt or an uncoded line; colouring them red teaches a site clerk that an
 * ordinary correction is something gone wrong.
 *
 * The column that earns its place is the **return reason**, because it is what
 * the site corrects against — and the "Returned, needs correcting" filter is the
 * working list this screen exists for.
 */
class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first: a returned expense sitting three weeks uncorrected is
            // a cost that will miss its period entirely.
            ->defaultSort('incurred_on')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('incurred_on')->label('Incurred')->date()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('costCode.code')->label('Cost code')->searchable(),

                TextColumn::make('receipt_reference')->label('Receipt')->searchable(),
                TextColumn::make('description')->limit(32)->searchable(),

                TextColumn::make('amount')->money('PHP')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseStatus $state): string => ucfirst($state->value))
                    ->color(fn (ExpenseStatus $state): string => match ($state) {
                        ExpenseStatus::Posted => 'success',
                        ExpenseStatus::Validated, ExpenseStatus::Coded => 'info',
                        // Not danger: a returned expense is ordinary, and the
                        // site is expected to fix and resubmit it.
                        ExpenseStatus::Returned => 'warning',
                        ExpenseStatus::Captured => 'gray',
                    }),

                // What the site corrects against.
                TextColumn::make('return_reason')
                    ->label('Returned because')
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('posted_at')
                    ->label('In ledger')
                    ->dateTime()
                    ->placeholder('Not posted')
                    ->toggleable(),
            ])
            ->recordActions([
                /*
                 * Returning an expense to site BARS its receipt from the period
                 * it was returned from — the same receipt cannot be re-submitted
                 * into the month it was rejected in, which is how a disputed cost
                 * quietly becomes an accepted one.
                 */
                Action::make('returnExpense')
                    ->label('Return to site')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->schema([
                        Textarea::make('reason')
                            ->required()
                            ->rows(2)
                            ->helperText('What is wrong with it. The receipt is barred from this period until somebody clears it.'),
                    ])
                    ->action(fn (Expense $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Returned to site.',
                        fn () => app(ExpenseService::class)->returnToSite($record, (string) $data['reason'], self::actingUser()),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(ExpenseStatus::cases())
                        ->mapWithKeys(fn (ExpenseStatus $case): array => [$case->value => ucfirst($case->value)])
                        ->all()
                ),

                Filter::make('returned')
                    ->label('Returned, needs correcting')
                    ->query(fn (Builder $query): Builder => $query->where('status', ExpenseStatus::Returned)),
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
