<?php

namespace App\Domain\Approvals;

use DomainException;

/**
 * The user does not hold the role the tier names.
 *
 * Checked in the service, not in the UI. PLAN.md §5 is explicit that a control
 * enforced only in a Filament form is bypassed by a queued job or an import.
 */
class ApproverLacksAuthorityException extends DomainException {}
