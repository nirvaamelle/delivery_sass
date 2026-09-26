<?php

namespace App\Domain\Opex;

use DomainException;

/**
 * This expense was returned from this period and cannot be charged to it.
 *
 * Its own type because it is the one refusal a site clerk can act on rather than
 * report: the expense is legitimate, and it belongs in the next period. Every
 * other refusal in expense capture means something is missing.
 */
class PeriodBarredException extends DomainException {}
