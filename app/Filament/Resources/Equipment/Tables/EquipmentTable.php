<?php

namespace App\Filament\Resources\Equipment\Tables;

use App\Domain\Equipment\DepreciationService;
use App\Domain\Equipment\EquipmentService;
use App\Domain\Equipment\EquipmentStatus;
use App\Domain\Equipment\Ownership;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Models\Equipment;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * The equipment register — F5.
 *
 * The two actions here call `EquipmentService`, which is what refuses to send a
 * machine to a second project while the first assignment is still open. The
 * P0-14 rule holds: no action writes a status column, because the status is a
 * consequence of an assignment rather than a field somebody sets.
 *
 * "On project" is computed from the open assignment rather than stored on the
 * machine. A `current_project_id` column would answer only for today, and the
 * question fuel costs are attributed by is "where was it in May".
 */
class EquipmentTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->recordUrl(fn (Equipment $record): string => EquipmentResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('description')->searchable()->limit(30),

                TextColumn::make('ownership')
                    ->badge()
                    ->color(fn (Ownership $state): string => $state === Ownership::Owned ? 'success' : 'gray'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (EquipmentStatus $state): string => match ($state) {
                        EquipmentStatus::Deployed => 'info',
                        EquipmentStatus::Available => 'success',
                        EquipmentStatus::UnderRepair => 'warning',
                        EquipmentStatus::Returned, EquipmentStatus::Disposed => 'gray',
                    }),

                TextColumn::make('on_project')
                    ->label('On project')
                    ->state(fn (Equipment $record): string => app(EquipmentService::class)
                        ->openAssignmentFor($record)?->project()->value('code') ?? '—'),

                TextColumn::make('acquisition_cost')->label('Cost')->money('PHP')->toggleable(),

                // Depreciation is a schedule, so what the register shows is
                // whether one exists — not a figure recomputed on the page.
                TextColumn::make('scheduled_depreciation')
                    ->label('Depreciation')
                    ->state(fn (Equipment $record): string => $record->schedules()->exists()
                        ? app(DepreciationService::class)->totalScheduled($record)
                        : 'not scheduled')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(EquipmentStatus::cases())
                        ->mapWithKeys(fn (EquipmentStatus $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
                        ->all()
                ),
                SelectFilter::make('ownership')->options(
                    collect(Ownership::cases())
                        ->mapWithKeys(fn (Ownership $case): array => [$case->value => ucfirst($case->value)])
                        ->all()
                ),
            ])
            ->recordActions([
                Action::make('assign')
                    ->icon(Heroicon::OutlinedMapPin)
                    // Hidden rather than shown-and-failing: a machine already on
                    // a site cannot be assigned, and an action that always
                    // errors is a bug report waiting to be filed.
                    ->visible(fn (Equipment $record): bool => ! $record->status->isTerminal()
                        && app(EquipmentService::class)->openAssignmentFor($record) === null)
                    ->schema([
                        Select::make('project_id')
                            ->label('Project')
                            ->options(fn (): array => Project::query()->pluck('code', 'id')->all())
                            ->required(),
                        DatePicker::make('assigned_on')->required()->default(now()),
                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(function (Equipment $record, array $data): void {
                        app(EquipmentService::class)->assign(
                            $record,
                            Project::query()->findOrFail($data['project_id']),
                            Carbon::parse($data['assigned_on']),
                            auth()->user(),
                            $data['remarks'] ?? null,
                        );
                    }),

                Action::make('release')
                    ->label('Demobilize')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (Equipment $record): bool => app(EquipmentService::class)->openAssignmentFor($record) !== null)
                    ->schema([
                        DatePicker::make('released_on')->required()->default(now()),
                        Textarea::make('remarks')->rows(2)
                            ->helperText('Slide 9 asks for equipment to be returned AND logged.'),
                    ])
                    ->action(function (Equipment $record, array $data): void {
                        $assignment = app(EquipmentService::class)->openAssignmentFor($record);

                        if ($assignment === null) {
                            return;
                        }

                        app(EquipmentService::class)->release(
                            $assignment,
                            Carbon::parse($data['released_on']),
                            $data['remarks'] ?? null,
                            auth()->user(),
                        );
                    }),
            ]);
    }
}
