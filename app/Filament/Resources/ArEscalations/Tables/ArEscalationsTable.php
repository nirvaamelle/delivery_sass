<?php

namespace App\Filament\Resources\ArEscalations\Tables;

use App\Domain\Billing\AgingBucket;
use App\Domain\Billing\ArAgingService;
use App\Models\ArEscalation;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * F13's escalations.
 *
 * The finding's whole point is that a report is something somebody has to open
 * and an escalation arrives. This screen is the arriving half made visible, and
 * the columns that matter are the recipient and the age — an escalation with
 * neither is a status change.
 */
class ArEscalationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('escalated_at', 'desc')
            ->columns([
                TextColumn::make('invoice.number')->label('Invoice')->searchable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('addressed_to_role')
                    ->label('Addressed to')
                    ->badge(),

                TextColumn::make('days_outstanding')->label('Days')->sortable(),

                TextColumn::make('bucket')
                    ->badge()
                    ->color(fn (AgingBucket $state): string => match ($state) {
                        AgingBucket::OverNinety => 'danger',
                        AgingBucket::SixtyOneToNinety => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('amount_outstanding')->label('Outstanding')->money('PHP')->sortable(),

                TextColumn::make('acknowledged_at')
                    ->label('Acknowledged')
                    ->dateTime()
                    ->placeholder('Not yet'),

                TextColumn::make('resolution')->limit(40)->toggleable(),
            ])
            ->recordActions([
                /*
                 * F13. An escalation is closed by saying what was DONE about it,
                 * not by ticking it: "chased the client" and "the client disputes
                 * the retention" lead to different next steps, and the next sweep
                 * re-raises anything still outstanding.
                 */
                Action::make('acknowledgeEscalation')
                    ->label('Acknowledge')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (ArEscalation $record): bool => $record->acknowledged_at === null)
                    ->schema([
                        Textarea::make('resolution')
                            ->required()
                            ->rows(3)
                            ->helperText('What was done, or what the client said. This is what the next reviewer reads.'),
                    ])
                    ->action(function (ArEscalation $record, array $data, Action $action): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            Notification::make()->danger()->title('Sign in to acknowledge an escalation.')->send();
                            $action->halt();

                            return;
                        }

                        try {
                            app(ArAgingService::class)->acknowledge($record, $user, (string) $data['resolution']);
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title('Escalation acknowledged.')->send();
                    }),
            ])
            ->filters([
                Filter::make('unacknowledged')
                    ->label('Still open')
                    ->query(fn (Builder $query): Builder => $query->whereNull('acknowledged_at')),
            ]);
    }
}
