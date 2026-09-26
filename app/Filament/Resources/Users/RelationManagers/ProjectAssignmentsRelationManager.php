<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectRole;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

/**
 * Which projects an account sees (P6-01), through ProjectAccessService.
 *
 * A role that sees every project (finance, the managing director, admin) needs
 * no assignment; everybody else sees only what is listed here.
 */
class ProjectAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'projectAssignments';

    protected static ?string $title = 'Project assignments';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('assigned_on', 'desc')
            ->columns([
                TextColumn::make('project_code')
                    ->label('Project')
                    ->state(fn (ProjectAssignment $record): string => (string) $this->projectOf($record)?->code),
                TextColumn::make('role')
                    ->formatStateUsing(fn (ProjectRole $state): string => ucfirst(str_replace('_', ' ', $state->value))),
                TextColumn::make('assigned_on')->label('Since')->date(),
            ])
            ->headerActions([
                Action::make('assignProject')
                    ->label('Assign to project')
                    ->icon('heroicon-o-plus')
                    ->schema([
                        Select::make('project_id')
                            ->label('Project')
                            ->searchable()
                            ->required()
                            // Every project: an administrator assigns people to
                            // jobs regardless of the projects they see themselves.
                            ->options(fn (): array => Project::withoutProjectScope(fn () => Project::query()->orderBy('code')->get())
                                ->mapWithKeys(fn (Project $project): array => [$project->getKey() => $project->code.' — '.$project->name])
                                ->all()),

                        Select::make('role')
                            ->label('Role on the project')
                            ->required()
                            ->options(collect(ProjectRole::cases())->mapWithKeys(fn (ProjectRole $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])->all()),
                    ])
                    ->action(function (array $data, Action $action): void {
                        $project = Project::withoutProjectScope(fn (): ?Project => Project::query()->find($data['project_id']));

                        try {
                            if ($project === null) {
                                throw new DomainException('That project no longer exists.');
                            }

                            app(ProjectAccessService::class)->assign($this->account(), $project, ProjectRole::from((string) $data['role']), $this->actingUser());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title('Assigned.')->send();
                    }),
            ])
            ->recordActions([
                Action::make('unassignProject')
                    ->label('Remove')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The account stops seeing this project immediately.')
                    ->action(function (ProjectAssignment $record): void {
                        $project = $this->projectOf($record);

                        if ($project !== null) {
                            app(ProjectAccessService::class)->unassign($this->account(), $project);
                        }
                    }),
            ]);
    }

    private function projectOf(ProjectAssignment $assignment): ?Project
    {
        return Project::withoutProjectScope(fn (): ?Project => Project::query()->find($assignment->project_id));
    }

    private function account(): User
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof User) {
            throw new LogicException('The assignments table has no account.');
        }

        return $owner;
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
