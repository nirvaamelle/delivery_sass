<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Hris\AllowanceService;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Recurring allowances on the 201 file, through AllowanceService.
 *
 * **The amount is never shown**, for the reason the rate is not: an allowance
 * beside a name is part of somebody's pay, on a screen more than one person
 * opens. It is typed once, when granted.
 *
 * A change of amount is an end and a new grant, never an edit, so a paid cutoff
 * is never restated.
 */
class AllowancesRelationManager extends RelationManager
{
    protected static string $relationship = 'allowances';

    protected static ?string $title = 'Allowances';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('name'),
                IconColumn::make('taxable')->boolean(),
                TextColumn::make('effective_from')->label('From')->date(),
                TextColumn::make('effective_to')->label('To')->date()->placeholder('In force'),
            ])
            ->headerActions([
                Action::make('grantAllowance')
                    ->label('Grant allowance')
                    ->icon('heroicon-o-plus')
                    ->modalDescription('Paid in full every cutoff it is in force on. To change an amount, end this allowance and grant a new one.')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('amount')->label('Amount per cutoff')->required()->rules(['numeric', 'gt:0']),
                        Toggle::make('taxable')
                            ->helperText('Taxable allowances are added to gross pay; non-taxable ones to net pay only.')
                            ->default(true),
                        DatePicker::make('effective_from')->label('Effective from')->required()->default(now()),
                    ])
                    ->action(function (array $data, Action $action): void {
                        try {
                            app(AllowanceService::class)->grant(
                                $this->employee(),
                                (string) $data['name'],
                                (string) $data['amount'],
                                (bool) $data['taxable'],
                                Carbon::parse($data['effective_from']),
                                $this->actingUser(),
                            );
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title('Allowance granted.')->send();
                    }),
            ])
            ->recordActions([
                Action::make('endAllowance')
                    ->label('End')
                    ->icon('heroicon-o-stop-circle')
                    ->color('warning')
                    ->visible(fn (EmployeeAllowance $record): bool => $record->effective_to === null)
                    ->schema([
                        DatePicker::make('effective_to')->label('Last day in force')->required()->default(now()),
                    ])
                    ->action(function (EmployeeAllowance $record, array $data, Action $action): void {
                        try {
                            app(AllowanceService::class)->end($record, Carbon::parse($data['effective_to']), $this->actingUser());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }
                    }),
            ]);
    }

    private function employee(): Employee
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Employee) {
            throw new LogicException('The allowances table has no employee.');
        }

        return $owner;
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
