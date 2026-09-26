<?php

namespace App\Filament\Resources\CashAdvances\Tables;

use App\Domain\Opex\CashAdvanceService;
use App\Domain\Opex\CashAdvanceStatus;
use App\Models\CashAdvance;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cash advances, and what is still unaccounted for.
 *
 * **Outstanding is computed, never read from a column** — the record itself has
 * no such column, deliberately (P4-02). This screen and the day-26 sweep ask the
 * same service the same question, so they cannot disagree about what somebody
 * owes; and it is the number a site clerk checks before their next payslip.
 *
 * `ChargedToPayroll` is shown plainly rather than hidden. Somebody whose advance
 * was swept is about to be short a fortnight, and the screen is where they find
 * out before the payslip tells them.
 */
class CashAdvancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('released_on')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('employee.employee_number')->label('Held by')->searchable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('released_on')->label('Released')->date()->sortable(),
                TextColumn::make('amount')->money('PHP')->sortable(),

                /*
                 * Computed from the liquidations, like the day-26 job computes
                 * it. A stored figure here would be a second answer to the
                 * question "what does this person still owe".
                 */
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->money('PHP')
                    ->state(fn (CashAdvance $record): string => app(CashAdvanceService::class)->outstandingFor($record)),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CashAdvanceStatus $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->color(fn (CashAdvanceStatus $state): string => match ($state) {
                        CashAdvanceStatus::Liquidated => 'success',
                        CashAdvanceStatus::PartlyLiquidated => 'info',
                        CashAdvanceStatus::Released => 'warning',
                        // The one somebody needs to know about before payday.
                        CashAdvanceStatus::ChargedToPayroll => 'danger',
                    }),

                TextColumn::make('charged_amount')
                    ->label('Charged to payroll')
                    ->money('PHP')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('purpose')->limit(32)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(CashAdvanceStatus::cases())
                        ->mapWithKeys(fn (CashAdvanceStatus $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
                        ->all()
                ),

                // The list the day-26 sweep will take if nobody liquidates first.
                Filter::make('open')
                    ->label('Still to liquidate')
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                        CashAdvanceStatus::Released,
                        CashAdvanceStatus::PartlyLiquidated,
                    ])),
            ]);
    }
}
