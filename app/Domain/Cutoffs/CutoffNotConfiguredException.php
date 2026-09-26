<?php

namespace App\Domain\Cutoffs;

use DomainException;

/**
 * No calendar covers the document's date.
 *
 * Treated as an error rather than as permission to post. Reading a missing
 * calendar as "open" would silently defeat the control for precisely the
 * periods nobody configured, which is the opposite of what a cutoff is for.
 */
class CutoffNotConfiguredException extends DomainException {}
