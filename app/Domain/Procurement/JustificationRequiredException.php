<?php

namespace App\Domain\Procurement;

use DomainException;

/**
 * A sole-source justification arrived without a written explanation.
 *
 * PLAN.md §5 says "written justification", and it means written. A reason code
 * with no narrative records that somebody clicked a dropdown; it does not tell
 * a reviewer why the canvass was skipped, which is the only thing the document
 * exists to say.
 */
class JustificationRequiredException extends DomainException {}
