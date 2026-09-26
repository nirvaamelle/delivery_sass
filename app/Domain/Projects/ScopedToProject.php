<?php

namespace App\Domain\Projects;

/**
 * Applies P6-01's row-level scope to a model that carries a project.
 *
 * A trait rather than a base class, because the models it goes on already
 * extend Eloquent's and have nothing else in common — a punchlist and a payroll
 * run share only the column.
 */
trait ScopedToProject
{
    public static function bootScopedToProject(): void
    {
        static::addGlobalScope(new ProjectScope);
    }

    /**
     * Run a callback with this model's project scope lifted.
     *
     * Deliberately not a query macro like `withoutGlobalScope()`: that reads as
     * a convenience, and stepping around a row-level access rule should read as
     * a decision. Every call site can be found by searching for the name.
     */
    public static function withoutProjectScope(callable $callback): mixed
    {
        return ProjectScope::withoutScope($callback);
    }
}
