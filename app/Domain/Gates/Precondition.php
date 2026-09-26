<?php

namespace App\Domain\Gates;

/**
 * One declarative precondition on a document transition.
 *
 * Preconditions are objects rather than closures so each one has a stable name
 * that can be reported to the user, listed in an admin screen, and asserted in
 * a test. "The gate failed" is not an answer anybody can act on; "no tabulated
 * bid" is.
 */
interface Precondition
{
    /**
     * A stable identifier, kebab-case — reported alongside the failure.
     */
    public function name(): string;

    /**
     * May the transition proceed for this subject?
     */
    public function passes(object $subject): bool;

    /**
     * What to tell the user when it does not.
     */
    public function failureMessage(object $subject): string;
}
