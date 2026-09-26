<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Domain\Hris\EmployeeService;
use App\Domain\Hris\EmploymentStatus;
use App\Filament\Resources\Employees\EmployeesResource;
use App\Models\Employee;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The 201 register.
 *
 * **Nothing sensitive is on this screen**, and that is the design rather than an
 * omission. The vendor register established the rule for bank details in P1-03a;
 * here it covers government numbers, salary rates and everything else PLAN.md §3
 * encrypts. A register that decrypts and prints them hands back exactly what the
 * encryption was for — on a panel every foreman can open.
 *
 * What the screen shows instead is the state a payroll clerk needs: whether the
 * 201 file is complete enough to pay against. "Rate on file" and "Bank details"
 * are booleans computed from the encrypted columns, so the answer is visible
 * without the value being readable.
 */
class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('employee_number')
            // Clicking a row opens the 201 file for editing.
            ->recordUrl(fn (Employee $record): string => EmployeesResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('employee_number')->label('Number')->searchable()->sortable(),

                TextColumn::make('last_name')
                    ->label('Name')
                    ->searchable(['first_name', 'last_name'])
                    ->state(fn (Employee $record): string => $record->fullName()),

                TextColumn::make('position')->searchable()->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (EmploymentStatus $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->color(fn (EmploymentStatus $state): string => match ($state) {
                        EmploymentStatus::Active => 'success',
                        EmploymentStatus::Resigned, EmploymentStatus::EndOfContract => 'gray',
                        EmploymentStatus::Terminated => 'danger',
                    }),

                TextColumn::make('date_hired')->label('Hired')->date()->sortable(),

                /*
                 * Computed from the rate history, and the figure itself is never
                 * shown. A payroll run refuses an employee with no rate in force,
                 * so this is the column that says WHY somebody fell off a register
                 * — without putting their pay on a shared screen.
                 */
                IconColumn::make('has_rate')
                    ->label('Rate on file')
                    ->boolean()
                    ->state(fn (Employee $record): bool => app(EmployeeService::class)->rateOn($record, now()) !== null),

                // Same reasoning: a bank upload refuses somebody with no account,
                // and this says so without printing the number.
                IconColumn::make('has_bank_details')
                    ->label('Bank details')
                    ->boolean()
                    ->state(fn (Employee $record): bool => trim((string) $record->bank_account_number) !== ''),

                TextColumn::make('separated_on')
                    ->label('Left')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(EmploymentStatus::cases())
                        ->mapWithKeys(fn (EmploymentStatus $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
                        ->all()
                ),
            ]);
    }
}
