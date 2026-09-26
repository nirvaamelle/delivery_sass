<?php

namespace App\Domain\Billing;

use DomainException;

/**
 * The milestone bills above what the client has verified.
 *
 * Slide 3's third gate rule — "no billing without a verified statement of
 * accomplishment" — with the part that is easy to miss: the statement has to
 * actually REACH the milestone being billed. Complete paperwork on 42% of the
 * works does not open a 50% billing.
 */
class InsufficientAccomplishmentException extends DomainException {}
