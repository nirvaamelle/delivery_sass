<?php

namespace App\Filament\Resources\PayrollDeductions\Tables;

use App\Models\PayrollDeduction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * F17's cross-chain write, made visible.
 *
 * PHASE-PLAN.md calls this "the kind of link that quietly never ships", and a
 * link nobody can see is halfway to not having shipped. This screen is where an
 * HR clerk finds out that an OPEX cutoff has created a payroll deduction —
 * before the employee finds out from their payslip.
 *
 * The **advance** and the **period it was swept from** are both on the row,
 * because "why is my pay short" has exactly one useful answer: which advance,
 * from which month.
 */
class PayrollDeductionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('raised_at', 'desc')
            ->columns([
                TextColumn::make('employee.employee_number')->label('Employee')->searchable(),

                // The answer to "why is my pay short".
                TextColumn::make('cashAdvance.number')->label('Advance')->searchable(),

                TextColumn::make('period')
                    ->label('Swept from')
                    ->state(fn (PayrollDeduction $record): string => sprintf('%d-%02d', $record->period_year, $record->period_month)),

                TextColumn::make('amount')->money('PHP')->sortable(),

                TextColumn::make('applied_at')
                    ->label('Applied')
                    ->dateTime()
                    ->placeholder('Not yet')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'warning' : 'success'),

                TextColumn::make('reason')->limit(48)->toggleable(),
            ])
            ->filters([
                // The list the next payroll run will take.
                Filter::make('pending')
                    ->label('Not yet applied')
                    ->query(fn (Builder $query): Builder => $query->whereNull('applied_at')),
            ]);
    }
}
