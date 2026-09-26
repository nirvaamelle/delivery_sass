<?php

namespace App\Domain\Projects;

/**
 * Gated transitions on a project.
 *
 * Named rather than stringly-typed because the Gatekeeper refuses an undeclared
 * transition: a typo would otherwise be caught only at runtime, and only on the
 * path nobody tested.
 */
enum ProjectTransition: string
{
    case RaisePurchaseRequisition = 'raise-purchase-requisition';
}
