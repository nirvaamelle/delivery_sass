<?php

namespace App\Domain\Projects;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DomainException;

/**
 * Opening and maintaining a project.
 *
 * Projects had no domain door: factories and seeders made them, and nobody could
 * open one without the database. This is the door, and it holds the rules the
 * rest of the build already assumes.
 *
 * **Two states have owners elsewhere.** POST-CONSTRUCTION is reached only through
 * the substantial completion certificate (SubstantialCompletionService), which is
 * what the defects liability period and retention date run from. CLOSED is
 * reached only through close-out (ProjectCloseoutService), which refuses while
 * retention is uncollected. This service refuses both.
 *
 * **Phase moves forward one step at a time**, and **the code is fixed** — it is
 * snapshotted onto ledger rows and printed on the paperwork.
 */
class ProjectService
{
    private const REQUIRED = ['code', 'name', 'client_name'];

    /** What a details edit may change. Everything else has its own act or owner. */
    private const EDITABLE = ['name', 'client_name'];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function open(Organization $organization, array $attributes, ?User $by = null): Project
    {
        foreach (self::REQUIRED as $field) {
            if (blank(is_string($attributes[$field] ?? null) ? trim($attributes[$field]) : ($attributes[$field] ?? null))) {
                throw new InvalidProjectDetail($field, sprintf('A project needs %s — every document raised against it prints it.', $field));
            }
        }

        if (isset($attributes['phase']) && $this->phaseOf($attributes['phase']) !== ProjectPhase::ProjectAcquisition) {
            throw new InvalidProjectDetail('phase', 'A new project opens at project acquisition (phase) and moves forward from there one step at a time.');
        }

        if (isset($attributes['status']) && $this->statusOf($attributes['status']) !== ProjectStatus::Active) {
            throw new InvalidProjectDetail('status', 'A new project opens active (status). Closing belongs to close-out; holding is its own act.');
        }

        $code = trim((string) $attributes['code']);

        // Past the project scope: a user assigned to nothing must still be told a
        // taken code is taken, not meet the unique index.
        $taken = Project::withoutProjectScope(fn (): bool => Project::query()->where('code', $code)->exists());

        if ($taken) {
            throw new InvalidProjectDetail('code', sprintf('Project code %s is already in use.', $code));
        }

        $project = Project::create([
            'organization_id' => $organization->getKey(),
            'code' => $code,
            'name' => trim((string) $attributes['name']),
            'client_name' => trim((string) $attributes['client_name']),
            'phase' => ProjectPhase::ProjectAcquisition,
            'status' => ProjectStatus::Active,
        ]);

        activity()->performedOn($project)->causedBy($by)->log('project-opened');

        return $project;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(Project $project, array $attributes, ?User $by = null): Project
    {
        $this->refuseIfClosed($project);

        $locked = array_values(array_diff(array_keys($attributes), self::EDITABLE));

        if ($locked !== []) {
            throw new InvalidProjectDetail($locked[0], sprintf(
                '%s cannot be changed here — the code and company are fixed, and phase and status have their own acts.',
                $locked[0],
            ));
        }

        foreach ($attributes as $field => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if (blank($value)) {
                throw new InvalidProjectDetail($field, sprintf('%s cannot be blank.', $field));
            }

            $attributes[$field] = (string) $value;
        }

        $project->fill($attributes);
        $changed = array_keys($project->getDirty());
        $project->save();

        if ($changed !== []) {
            activity()->performedOn($project)->causedBy($by)
                ->withProperties(['fields' => $changed])
                ->log('project-details-updated');
        }

        return $project;
    }

    public function advancePhase(Project $project, ProjectPhase $to, ?User $by = null): Project
    {
        $this->refuseIfClosed($project);

        if ($to === ProjectPhase::PostConstruction) {
            throw new DomainException('Post-construction is reached through the substantial completion certificate, not by hand.');
        }

        $cases = ProjectPhase::cases();
        $from = (int) array_search($project->phase, $cases, true);
        $target = (int) array_search($to, $cases, true);

        if ($target <= $from) {
            throw new DomainException(sprintf('A project cannot move backwards, or stay where it is, from %s to %s.', $project->phase->value, $to->value));
        }

        if ($target !== $from + 1) {
            throw new DomainException('A project moves forward one phase at a time.');
        }

        $previous = $project->phase;
        $project->phase = $to;
        $project->save();

        activity()->performedOn($project)->causedBy($by)
            ->withProperties(['from' => $previous->value, 'to' => $to->value])
            ->log('project-phase-advanced');

        return $project;
    }

    public function putOnHold(Project $project, string $reason, ?User $by = null): Project
    {
        $this->refuseIfClosed($project);

        if (trim($reason) === '') {
            throw new DomainException('Putting a project on hold needs a reason.');
        }

        if ($project->status === ProjectStatus::OnHold) {
            throw new DomainException('The project is already on hold.');
        }

        $project->status = ProjectStatus::OnHold;
        $project->save();

        activity()->performedOn($project)->causedBy($by)
            ->withProperties(['reason' => trim($reason)])
            ->log('project-put-on-hold');

        return $project;
    }

    public function resume(Project $project, ?User $by = null): Project
    {
        $this->refuseIfClosed($project);

        if ($project->status !== ProjectStatus::OnHold) {
            throw new DomainException('Only a project on hold can be resumed.');
        }

        $project->status = ProjectStatus::Active;
        $project->save();

        activity()->performedOn($project)->causedBy($by)->log('project-resumed');

        return $project;
    }

    private function refuseIfClosed(Project $project): void
    {
        if ($project->status === ProjectStatus::Closed) {
            throw new DomainException('The project is closed, and close-out is final.');
        }
    }

    private function phaseOf(mixed $value): ?ProjectPhase
    {
        return $value instanceof ProjectPhase ? $value : ProjectPhase::tryFrom((string) $value);
    }

    private function statusOf(mixed $value): ?ProjectStatus
    {
        return $value instanceof ProjectStatus ? $value : ProjectStatus::tryFrom((string) $value);
    }
}
