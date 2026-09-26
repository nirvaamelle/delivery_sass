<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Domain\Projects\InvalidProjectDetail;
use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectService;
use App\Domain\Projects\ProjectStatus;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Projects\ProjectsResource;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Edit a project's details, and move it by its own acts.
 *
 * **"Move to …"** offers only the next phase, and never post-construction —
 * that is the substantial completion certificate's. **Hold** needs a reason;
 * **Resume** takes it off hold. None of the three is offered on a closed project.
 * No delete: every document in the build points at a project.
 */
class EditProject extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = ProjectsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('advancePhase')
                ->label(fn (): string => 'Move to '.strtolower(ProjectsTable::label((string) $this->nextPhase()?->value)))
                ->icon('heroicon-o-arrow-right-circle')
                ->visible(fn (): bool => $this->project()->status !== ProjectStatus::Closed
                    && $this->nextPhase() !== null
                    && $this->nextPhase() !== ProjectPhase::PostConstruction)
                ->requiresConfirmation()
                ->modalDescription('Phase moves forward one step at a time and cannot be moved back.')
                ->action(function (Action $action): void {
                    $next = $this->nextPhase();

                    $this->attempt($action, fn () => app(ProjectService::class)->advancePhase($this->project(), $next ?? $this->project()->phase, $this->actingUser()));

                    Notification::make()->success()->title('Phase moved forward.')->send();
                }),

            Action::make('putOnHold')
                ->label('Put on hold')
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->visible(fn (): bool => $this->project()->status === ProjectStatus::Active)
                ->schema([
                    Textarea::make('reason')->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    $this->attempt($action, fn () => app(ProjectService::class)->putOnHold($this->project(), (string) $data['reason'], $this->actingUser()));

                    Notification::make()->success()->title('Project put on hold.')->send();
                }),

            Action::make('resume')
                ->label('Resume')
                ->icon('heroicon-o-play-circle')
                ->visible(fn (): bool => $this->project()->status === ProjectStatus::OnHold)
                ->requiresConfirmation()
                ->action(function (Action $action): void {
                    $this->attempt($action, fn () => app(ProjectService::class)->resume($this->project(), $this->actingUser()));

                    Notification::make()->success()->title('Project resumed.')->send();
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Project) {
            throw new LogicException('The project edit page was given something other than a project.');
        }

        try {
            // Code and company are disabled, so never submitted; only the two
            // editable fields are passed on.
            return app(ProjectService::class)->updateDetails($record, Arr::only($data, ['name', 'client_name']), $this->actingUser());
        } catch (InvalidProjectDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.name' => $e->getMessage()]);
        }
    }

    private function attempt(Action $action, callable $act): void
    {
        try {
            $act();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }

        $this->project()->refresh();
    }

    private function nextPhase(): ?ProjectPhase
    {
        $cases = ProjectPhase::cases();
        $index = array_search($this->project()->phase, $cases, true);

        return $index === false ? null : ($cases[$index + 1] ?? null);
    }

    private function project(): Project
    {
        $record = $this->getRecord();

        if (! $record instanceof Project) {
            throw new LogicException('The project edit page has no project.');
        }

        return $record;
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
