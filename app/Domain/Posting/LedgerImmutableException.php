<?php

namespace App\Domain\Posting;

use DomainException;

/**
 * Something tried to change or delete a posted ledger row.
 *
 * The database refuses this too — see the triggers on project_cost_ledger. This
 * exception exists so the model layer fails with an explanation rather than a
 * raw SQL error, not because it is the real defence.
 */
class LedgerImmutableException extends DomainException {}
