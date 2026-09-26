<?php

namespace App\Providers;

use App\Domain\Billing\BillingTransition;
use App\Domain\Gates\Gatekeeper;
use App\Domain\Gates\Preconditions\EmployeeHasSignedContract;
use App\Domain\Gates\Preconditions\FinalBillingDeductionsApplied;
use App\Domain\Gates\Preconditions\OpexVarianceExplained;
use App\Domain\Gates\Preconditions\PayrollVarianceExplained;
use App\Domain\Gates\Preconditions\ProjectHasOpenBudget;
use App\Domain\Gates\Preconditions\ProjectHasSignedContract;
use App\Domain\Gates\Preconditions\PurchaseOrderIsCountersigned;
use App\Domain\Hris\HiringTransition;
use App\Domain\Hris\PayrollTransition;
use App\Domain\Mobilization\MobilizationTransition;
use App\Domain\Opex\OpexTransition;
use App\Domain\Projects\ProjectTransition;
use App\Models\Billing;
use App\Models\Employee;
use App\Models\OpexPeriod;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\PurchaseOrder;
use Illuminate\Support\ServiceProvider;

/**
 * Where every gate in the system is declared.
 *
 * One file, so the full set of preconditions the business runs on can be read
 * top to bottom rather than hunted for across resources and jobs. PLAN.md §5's
 * control table is the specification for what belongs here, and each phase adds
 * its chain's gates as the documents arrive.
 */
class GateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $gatekeeper = $this->app->make(Gatekeeper::class);

        /*
         * F14 — no PR against a project without a signed contract AND an opened
         * budget. Declared on the project rather than on the requisition
         * because the requisition does not exist until Phase 1, and because
         * every future document that spends project money needs the same rule.
         */
        $gatekeeper->define(Project::class, ProjectTransition::RaisePurchaseRequisition->value, [
            new ProjectHasSignedContract,
            new ProjectHasOpenBudget,
        ]);

        /*
         * F2 — no mobilization without a COUNTERSIGNED purchase order. Declared
         * against the order rather than the mobilization, because the
         * mobilization does not exist yet at the moment the question is asked,
         * and the fact being tested is a fact about the order.
         */
        $gatekeeper->define(PurchaseOrder::class, MobilizationTransition::Mobilize->value, [
            new PurchaseOrderIsCountersigned,
        ]);

        /*
         * Slide 7 — no shift without an employment contract signed before it.
         * Declared against the employee for the same reason F2's gate is
         * declared against the order: the timelog does not exist at the moment
         * the question is asked, and the fact being tested belongs to the
         * contract.
         */
        $gatekeeper->define(Employee::class, HiringTransition::Work->value, [
            new EmployeeHasSignedContract,
        ]);

        /*
         * F10 — no payroll register approved while a project's labour cost has
         * moved against the last cutoff without a written explanation. The
         * second, independent variance gate PHASE-PLAN.md asks for, beside the
         * OPEX month-end close.
         */
        $gatekeeper->define(PayrollRun::class, PayrollTransition::ApproveRegister->value, [
            new PayrollVarianceExplained,
        ]);

        /*
         * Slide 8's day 30 — no month-end close while a budget variance above
         * the threshold is unexplained. The OPEX twin of the payroll gate above,
         * and deliberately a separate one: the chains close on different
         * calendars (F3) and answer to different reviewers.
         */
        $gatekeeper->define(OpexPeriod::class, OpexTransition::CloseMonth->value, [
            new OpexVarianceExplained,
        ]);

        /*
         * Slide 9 step 4 — no invoice over a back-charge that is not on the
         * final billing. Declared against the billing rather than the invoice
         * for the same reason F2's gate sits on the purchase order: the invoice
         * does not exist at the moment the question is asked, and the fact
         * being tested belongs to the billing.
         */
        $gatekeeper->define(Billing::class, BillingTransition::Invoice->value, [
            new FinalBillingDeductionsApplied,
        ]);
    }
}
