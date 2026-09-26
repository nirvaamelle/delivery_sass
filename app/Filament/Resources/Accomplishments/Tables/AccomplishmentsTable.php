<?php

namespace App\Filament\Resources\Accomplishments\Tables;

use App\Domain\Billing\AccomplishmentService;
use App\Domain\Billing\AccomplishmentStatus;
use App\Models\Accomplishment;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class AccomplishmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('period_end', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('percentage_complete')
                    ->label('Complete')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('previous_percentage')->label('Previous')->suffix('%'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AccomplishmentStatus $state): string => match ($state) {
                        AccomplishmentStatus::Verified => 'success',
                        AccomplishmentStatus::Measured => 'warning',
                        AccomplishmentStatus::Superseded => 'gray',
                    }),

                /*
                 * Two signature columns, not one. Joint means both, and the
                 * ordinary state of affairs for days at a time is a survey the
                 * contractor has signed and the client has not — which is
                 * exactly the state somebody needs to see on a list.
                 */
                IconColumn::make('contractor_signed')
                    ->label('Contractor')
                    ->boolean()
                    ->state(fn (Accomplishment $record): bool => $record->survey()->value('contractor_signed_at') !== null),

                IconColumn::make('client_signed')
                    ->label('Client')
                    ->boolean()
                    ->state(fn (Accomplishment $record): bool => $record->survey()->value('client_signed_at') !== null),

                TextColumn::make('period_end')->label('Period to')->date()->sortable(),
                TextColumn::make('remarks')->limit(40)->toggleable(),
            ])
            ->recordActions([
                /*
                 * Slide 6's joint survey. Both sides walk the works and both
                 * sides sign; the invoice rests on those two signatures, so
                 * each is its own act and the representatives are named before
                 * anybody signs.
                 */
                Action::make('openSurvey')
                    ->label('Open joint survey')
                    ->icon('heroicon-o-users')
                    ->visible(fn (Accomplishment $record): bool => $record->survey === null)
                    ->modalDescription('Names both representatives now, before either signs — a signature nobody can attribute is what an invoice would rest on otherwise.')
                    ->schema([
                        DatePicker::make('surveyed_on')->label('Surveyed on')->required()->default(now()),
                        TextInput::make('client_representative')->label('Client representative')->required()->maxLength(255),
                        TextInput::make('contractor_representative')->label('Contractor representative')->required()->maxLength(255),
                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(fn (Accomplishment $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Joint survey opened.',
                        fn () => app(AccomplishmentService::class)->openSurvey(
                            $record,
                            Carbon::parse($data['surveyed_on']),
                            (string) $data['client_representative'],
                            (string) $data['contractor_representative'],
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        ),
                    )),

                Action::make('signAsContractor')
                    ->label('Sign as contractor')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (Accomplishment $record): bool => $record->survey !== null
                        && $record->survey->contractor_signed_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('Signed against your own account.')
                    ->action(fn (Accomplishment $record, Action $action) => self::attempt(
                        $action,
                        'Signed for the contractor.',
                        fn () => app(AccomplishmentService::class)->signAsContractor($record->survey, self::actingUser()),
                    )),

                Action::make('signAsClient')
                    ->label('Record client signature')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Accomplishment $record): bool => $record->survey !== null
                        && $record->survey->client_signed_at === null)
                    ->schema([
                        TextInput::make('signatory')
                            ->label('Who signed for the client')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(fn (Accomplishment $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Client signature recorded — the accomplishment is verified.',
                        fn () => app(AccomplishmentService::class)->signAsClient($record->survey, (string) $data['signatory']),
                    )),
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
