<?php

namespace App\Domain\Projects;

use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Per-project row-level access — PLAN.md §3, deferred to Phase 6 since P0-14.
 *
 * **It is a query scope, not a view filter, and that is the whole design.** A
 * screen that filters what it renders leaves every hidden record addressable:
 * the row is still in the result set, still counted in a nav badge, still
 * returned through a relation, still exported. Applied globally on the model,
 * the restriction survives every route to the data — including the ones written
 * after this class.
 *
 * Three decisions about how it fails, each chosen against the direction that
 * fails open.
 *
 * **No assignment means no projects, never all of them.** The classic version
 * of this bug reads an empty assignment list as "no filter to apply" and hands
 * a new starter the whole company on their first login.
 *
 * **Unauthenticated means unscoped.** A queued payroll run, a console command,
 * the scheduler and a migration all execute with no user. Scoping them to
 * nobody's projects would not restrict anything — it would silently stop the
 * payroll.
 *
 * **Roles that must see everything are named in one place.** Finance closes the
 * books across projects and the managing director signs at tier 4. Exempting
 * them per screen would mean a bypass in every resource, and the one somebody
 * forgets is the one that matters.
 */
class ProjectScope implements Scope
{
    /**
     * Roles that see every project.
     *
     * PLACEHOLDER: Part D has not been asked which roles carry company-wide
     * visibility. These follow from the authority matrix the build already
     * seeds — the tiers that approve across projects are the tiers that have to
     * read across them — and are a config change, not a code change, when the
     * client answers.
     */
    public const UNSCOPED_ROLES = [
        'finance-manager',
        'managing-director',
        'admin',
    ];

    /** Set while a caller has deliberately stepped around the scope. */
    private static bool $disabled = false;

    /**
     * Run a callback with the scope lifted.
     *
     * Named and explicit so every place that reaches across projects can be
     * found by searching for it. Consolidation, the OPEX sweep and the seeders
     * genuinely have to; each is a decision somebody should be able to review.
     */
    public static function withoutScope(callable $callback): mixed
    {
        $previous = self::$disabled;
        self::$disabled = true;

        try {
            return $callback();
        } finally {
            self::$disabled = $previous;
        }
    }

    public static function isDisabled(): bool
    {
        return self::$disabled;
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (self::$disabled) {
            return;
        }

        $user = Auth::user();

        // No user: a job, a command, the scheduler, a migration. Not a
        // restriction to apply — an absence of anybody to restrict.
        if (! $user instanceof User) {
            return;
        }

        if ($this->seesEveryProject($user)) {
            return;
        }

        $column = $model->getTable() === 'projects'
            ? $model->qualifyColumn('id')
            : $model->qualifyColumn('project_id');

        // whereIn over a subquery rather than a join: a join would multiply
        // rows if the assignment table ever gained a second row per person, and
        // silently change every count built on top of this.
        $builder->whereIn($column, ProjectAssignment::query()
            ->where('user_id', $user->getKey())
            ->select('project_id'));
    }

    private function seesEveryProject(User $user): bool
    {
        foreach (self::UNSCOPED_ROLES as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
