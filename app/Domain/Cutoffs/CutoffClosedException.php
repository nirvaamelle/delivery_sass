<?php

namespace App\Domain\Cutoffs;

use DomainException;

/**
 * The period exists but has already closed. PLAN.md §5: nothing books after
 * the cutoff date.
 */
class CutoffClosedException extends DomainException {}
