<?php

namespace App\Domain\Gates;

use DomainException;

/**
 * The transition has no gate declaration at all.
 *
 * Treated as an error rather than as permission. If an undeclared transition
 * were simply allowed, a typo in a transition name would enforce nothing —
 * silently, and for as long as it took someone to notice. Transitions that are
 * genuinely ungated are declared with an empty precondition list, which says so
 * out loud.
 */
class UndeclaredTransitionException extends DomainException {}
