<?php

namespace App\Domain\Billing;

/**
 * The gated transitions on a billing.
 *
 * Raising the invoice is the one that matters: slide 9 puts the deductions
 * BEFORE it, and a transition name is what lets that be declared in
 * `GateServiceProvider` beside every other control rather than buried in the
 * collection service.
 */
enum BillingTransition: string
{
    case Invoice = 'invoice';
}
