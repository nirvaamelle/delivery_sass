<?php

namespace App\Domain\Procurement;

use DomainException;

/**
 * A sole-source award has no fully approved justification behind it.
 *
 * The exemption from the three-quote minimum is granted by the escalated
 * approval, not by the act of using it. A justification that exists but is half
 * signed looks complete on a list and is not — which is exactly the state this
 * refuses.
 */
class UnjustifiedSoleSourceException extends DomainException {}
