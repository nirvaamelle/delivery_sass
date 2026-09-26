<?php

namespace App\Domain\Requisitions;

use DomainException;

/**
 * The requisition asks for more than the cost code has left.
 *
 * PLAN.md §5's first control. This is the exception the Phase 0 exit gate is
 * built to provoke — the gate is not "a PR can be raised", it is "a PR is
 * rejected by the budget check".
 */
class BudgetExceededException extends DomainException {}
