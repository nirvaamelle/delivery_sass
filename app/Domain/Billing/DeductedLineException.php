<?php

namespace App\Domain\Billing;

use DomainException;

/**
 * A line the client already deducted has come back on a new submission.
 *
 * Its own type because this is the one refusal the QS needs to act on rather
 * than report: the line is blocked until it is re-measured, and the exception
 * names what to re-measure.
 */
class DeductedLineException extends DomainException {}
