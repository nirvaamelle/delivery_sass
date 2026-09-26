<?php

namespace App\Domain\Procurement;

use DomainException;

/**
 * The three documents disagree.
 *
 * Its own type rather than a bare DomainException because this is the refusal
 * PLAN.md §5's payment control is made of, and callers — the AP screen, an
 * importer, a queued job — need to tell "these figures do not reconcile" apart
 * from every other domain refusal in the chain.
 */
class MatchFailedException extends DomainException {}
