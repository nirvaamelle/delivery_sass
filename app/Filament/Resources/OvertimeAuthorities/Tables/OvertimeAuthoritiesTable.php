<?php

namespace App\Filament\Resources\OvertimeAuthorities\Tables;

use App\Domain\Hris\OvertimeService;
use App\Domain\Hris\OvertimeStatus;
use App\Domain\Hris\OvertimeType;
use App\Models\DailyTimeRecord;
use App\Models\OvertimeAuthority;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Overtime authorities, and what they actually buy.
 *
 * The column that earns its place is **Payable** — the smaller of hours
 * authorised and hours worked, computed. An authority for four hours on a day
 * somebody left at five shows 4 authorised and 0 payable, which is the whole
 * P3-06 rule made visible in one row. A screen showing only the authorised
 * figure would suggest four hours are owed.
 */
class OvertimeAuthoritiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('work_date', 'desc')
            ->columns([
                TextColumn::make('work_date')->label('Date')->date()->sortable(),
                TextColumn::make('employee.employee_number')->label('Employee')->searchable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (OvertimeType $state): string => match ($state) {
                        OvertimeType::Overtime => 'Overtime',
                        OvertimeType::NightDifferential => 'Night diff.',
                    }),

                TextColumn::make('hours_authorised')->label('Authorised'),

                /*
                 * Computed, and the point of the screen. Authorised-but-not-worked
                 * shows here as zero, which is the rule the deck states and the
                 * number a payroll clerk is actually asking about.
                 */
                TextColumn::make('payable')
                    ->label('Payable')
                    ->state(function (OvertimeAuthority $record): string {
                        $day = DailyTimeRecord::query()
                            ->where('employee_id', $record->employee_id)
                            ->whereDate('work_date', $record->work_date)
                            ->first();

                        if ($day === null) {
                            return '0.00';
                        }

                        return $record->type === OvertimeType::Overtime
                            ? app(OvertimeService::class)->payableOvertimeHours($day)
                            : app(OvertimeService::class)->payableNightHours($day);
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OvertimeStatus $state): string => ucfirst($state->value))
                    ->color(fn (OvertimeStatus $state): string => match ($state) {
                        OvertimeStatus::Approved => 'success',
                        OvertimeStatus::Requested => 'warning',
                        OvertimeStatus::Rejected => 'danger',
                    }),

                // Slide 7's "in writing", on the row. An approval with no
                // reference is the one worth asking about.
                TextColumn::make('written_reference')
                    ->label('In writing')
                    ->placeholder('—'),

                TextColumn::make('reason')->limit(40)->toggleable(),
            ])
            ->recordActions([
                /*
                 * Overtime is paid only where it was authorised IN WRITING and
                 * BEFORE the hours were worked — so approving demands the written
                 * reference, and payroll pays the smaller of worked and
                 * authorised hours.
                 */
                Action::make('approveOvertime')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (OvertimeAuthority $record): bool => $record->status === OvertimeStatus::Requested)
                    ->schema([
                        TextInput::make('written_reference')
                            ->label('Written authority reference')
                            ->required()
                            ->maxLength(255)
                            ->helperText('The signed slip or memo number. Overtime with no paper behind it is not payable.'),

                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(function (OvertimeAuthority $record, array $data, Action $action): void {
                        $user = self::actingUser();

                        if ($user === null) {
                            Notification::make()->danger()->title('Sign in to approve overtime.')->send();
                            $action->halt();

                            return;
                        }

                        self::attempt(
                            $action,
                            'Overtime approved.',
                            fn () => app(OvertimeService::class)->approve(
                                $record,
                                $user,
                                (string) $data['written_reference'],
                                filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                            ),
                        );
                    }),

                Action::make('rejectOvertime')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (OvertimeAuthority $record): bool => $record->status === OvertimeStatus::Requested)
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(function (OvertimeAuthority $record, array $data, Action $action): void {
                        $user = self::actingUser();

                        if ($user === null) {
                            Notification::make()->danger()->title('Sign in to reject overtime.')->send();
                            $action->halt();

                            return;
                        }

                        self::attempt(
                            $action,
                            'Overtime rejected.',
                            fn () => app(OvertimeService::class)->reject($record, $user, (string) $data['reason']),
                        );
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(OvertimeStatus::cases())
                        ->mapWithKeys(fn (OvertimeStatus $case): array => [$case->value => ucfirst($case->value)])
                        ->all()
                ),
                SelectFilter::make('type')->options(
                    collect(OvertimeType::cases())
                        ->mapWithKeys(fn (OvertimeType $case): array => [$case->value => ucwords(str_replace('_', ' ', $case->value))])
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
