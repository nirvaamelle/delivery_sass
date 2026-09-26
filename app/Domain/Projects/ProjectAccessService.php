<?php

namespace App\Domain\Projects;

use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who works on which project.
 *
 * The write side of P6-01. Assignments are the only input to the row-level
 * scope, so they go through a service for the same reason every other control
 * in this build does: a row written straight into the table grants access
 * nobody decided to grant.
 */
class ProjectAccessService
{
    /**
     * Put somebody on a project.
     *
     * @throws DomainException when they are already on it
     */
    public function assign(
        User $user,
        Project $project,
        ProjectRole $role,
        ?User $by = null,
        ?CarbonInterface $assignedOn = null,
    ): ProjectAssignment {
        // Read past the scope: the caller granting access is frequently
        // somebody who cannot see the project themselves — an administrator
        // setting up a job they will never work on.
        $existing = ProjectScope::withoutScope(fn () => ProjectAssignment::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->first());

        if ($existing !== null) {
            throw new DomainException(sprintf(
                '%s is already assigned to %s as %s. Two assignments are two answers to what somebody does on a job.',
                $user->name,
                $project->code,
                $existing->role->value,
            ));
        }

        return ProjectAssignment::query()->create([
            'project_id' => $project->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'assigned_on' => $assignedOn ?? now(),
            'assigned_by_user_id' => $by?->getKey(),
        ]);
    }

    /**
     * Take somebody off a project. The access goes with it.
     */
    public function unassign(User $user, Project $project): void
    {
        ProjectScope::withoutScope(fn () => ProjectAssignment::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->delete());
    }

    /**
     * @return Collection<int, ProjectAssignment>
     */
    public function forProject(Project $project): Collection
    {
        return ProjectScope::withoutScope(fn () => ProjectAssignment::query()
            ->with('user')
            ->where('project_id', $project->getKey())
            ->orderBy('role')
            ->get());
    }

    /**
     * @return Collection<int, ProjectAssignment>
     */
    public function forUser(User $user): Collection
    {
        return ProjectScope::withoutScope(fn () => ProjectAssignment::query()
            ->with('project')
            ->where('user_id', $user->getKey())
            ->get());
    }

    public function seesEveryProject(User $user): bool
    {
        foreach (ProjectScope::UNSCOPED_ROLES as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
