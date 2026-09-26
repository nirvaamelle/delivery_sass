<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Hris\LeaveService;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Service incentive leave on the 201 file, through LeaveService.
 *
 * The heading carries today's balance. A leave day is paid by the next payroll
 * run that has a worked day to post against; once paid it cannot be cancelled.
 */
class LeaveRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'leaveRecords';

    protected static ?string $title = 'Service incentive leave';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('leave_date', 'desc')
            ->description(fn (): string => sprintf(
                'Balance today: %s days. Five days a leave year after twelve months of service, from the hiring anniversary.',
                app(LeaveService::class)->balanceOn($this->employee(), now()),
            ))
            ->columns([
                TextColumn::make('leave_date')->label('Date')->date(),
                TextColumn::make('days'),
                TextColumn::make('reason')->limit(40)->placeholder('—'),
                TextColumn::make('state')
                    ->badge()
                    ->state(fn (LeaveRecord $record): string => match (true) {
                        $record->cancelled_at !== null => 'Cancelled',
                        $record->payroll_line_id !== null => 'Paid',
                        default => 'Unpaid',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Paid' => 'success',
                        'Cancelled' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->headerActions([
                Action::make('recordLeave')
                    ->label('Record leave')
                    ->icon('heroicon-o-plus')
                    ->schema([
                        DatePicker::make('leave_date')->label('Date')->required()->default(now()),
                        Select::make('days')->options(['1' => 'Whole day', '0.5' => 'Half day'])->default('1')->required(),
                        Textarea::make('reason')->rows(2),
                    ])
                    ->action(function (array $data, Action $action): void {
                        try {
                            app(LeaveService::class)->record(
                                $this->employee(),
                                Carbon::parse($data['leave_date']),
                                (string) $data['days'],
                                $data['reason'] ?? null,
                                $this->actingUser(),
                            );
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title('Leave recorded.')->send();
                    }),
            ])
            ->recordActions([
                Action::make('cancelLeave')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (LeaveRecord $record): bool => $record->cancelled_at === null && $record->payroll_line_id === null)
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (LeaveRecord $record, array $data, Action $action): void {
                        try {
                            app(LeaveService::class)->cancel($record, (string) $data['reason'], $this->actingUser());
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
            throw new LogicException('The leave table has no employee.');
        }

        return $owner;
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
