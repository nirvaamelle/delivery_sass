<?php

namespace App\Domain\Gates\Preconditions;

use App\Domain\Closeout\FinalBillingService;
use App\Domain\Gates\Precondition;
use App\Models\Billing;

/**
 * Slide 9 step 4: back-charges are applied BEFORE the invoice is raised.
 *
 * The service applies them when the final billing is raised, so in the ordinary
 * path this gate passes silently. It exists for the path that is not ordinary:
 * a row written by an importer, a console command, or a screen built later. An
 * invoice raised over an unapplied back-charge bills the client for money the
 * company already knows it will not collect, and the correction has to travel
 * back through a document the client is holding.
 *
 * A progress billing has nothing to apply and passes. Only the final one is
 * gated, because only the final one is where the deductions land.
 */
class FinalBillingDeductionsApplied implements Precondition
{
    public function name(): string
    {
        return 'final-billing-deductions-applied';
    }

    public function passes(object $subject): bool
    {
        if (! $subject instanceof Billing) {
            return false;
        }

        $service = app(FinalBillingService::class);

        if (! $service->isFinalBilling($subject)) {
            return true;
        }

        return $service->unappliedBackCharges($subject)->isEmpty();
    }

    public function failureMessage(object $subject): string
    {
        if (! $subject instanceof Billing) {
            return 'Not a billing.';
        }

        $charges = app(FinalBillingService::class)
            ->unappliedBackCharges($subject)
            ->pluck('number')
            ->implode(', ');

        return sprintf(
            'These back-charges are not on the final billing: %s. Slide 9 applies deductions and back-charges before the invoice, because an invoice already sent cannot be corrected quietly.',
            $charges,
        );
    }
}
