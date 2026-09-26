<?php

namespace App\Domain\Closeout;

use App\Domain\Billing\BillingService;
use App\Domain\Support\Money;
use App\Models\BackCharge;
use App\Models\Billing;
use App\Models\BillingMilestone;
use App\Models\FinalBillingDeduction;
use App\Models\Project;
use App\Models\Punchlist;
use App\Models\SalesInvoice;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Slide 9 step 4 — the final billing, and everything that comes off it.
 *
 * "Step 4 applies deductions and back-charges BEFORE the invoice", and
 * PHASE-PLAN.md draws the structural reading out of it: "final billing depends
 * on back-charges being computed first, which makes step 2 a hard predecessor
 * of step 4 across two different chains."
 *
 * **Before the invoice is the whole control, not a sequencing preference.** An
 * invoice raised at the gross and corrected afterwards has already been sent:
 * the client holds a document saying one number, the ledger says another, and
 * the difference is chased by whoever notices first. So deductions are applied
 * to the billing, the invoice is computed from the deducted net, and a
 * back-charge missing from the statement stops the invoice rather than
 * following it — that last part is a Gatekeeper precondition, declared with
 * every other control in `GateServiceProvider`.
 *
 * **The predecessor is enforced.** A cleared subcontractor defect nobody has
 * priced is an open question about how much the client owes, so the final
 * billing refuses to be raised over it and names the item. P5-02 built
 * `unpricedItems()` for exactly this call.
 *
 * The punchlist is not checked here. Acceptance of the turnover pack already
 * requires it closed (P5-04), so re-asserting it would be a second copy of one
 * rule — and the copy is the one that goes stale.
 *
 * **Back-charge deductions are derived, never typed.** The amount was computed
 * at punchlist clearing; a hand-entered one is a second opinion about a number
 * that already exists, and the two will differ.
 */
class FinalBillingService
{
    public function __construct(
        private readonly BillingService $billings,
        private readonly BackChargeService $backCharges,
        private readonly TurnoverService $turnovers,
    ) {}

    /**
     * Raise the final billing, with the deductions already on it.
     *
     * @param  array<int, array{line_key: string, description: string, amount: string, cost_code_id?: int}>  $lines
     * @param  array<int, array{type: FinalDeductionType, description: string, amount: string}>  $deductions
     *
     * @throws DomainException when the milestone is not the final one, turnover
     *                         has not been accepted, a cleared subcontractor
     *                         defect is unpriced, or the deductions swallow the
     *                         billing
     */
    public function raise(
        BillingMilestone $milestone,
        array $lines,
        User $by,
        array $deductions = [],
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
    ): Billing {
        if (! $this->isFinalMilestone($milestone)) {
            throw new DomainException(sprintf(
                'Milestone %s is not the final milestone of its schedule. Deductions and back-charges come off the final billing, not off a progress one.',
                $milestone->code,
            ));
        }

        $project = $milestone->schedule()->sole()->contract()->sole()->project()->sole();

        $this->assertTurnoverAccepted($project);
        $this->assertBackChargesPriced($project);

        return DB::transaction(function () use ($milestone, $lines, $by, $deductions, $periodStart, $periodEnd, $project): Billing {
            // Through BillingService, not around it. The document set, the
            // verified accomplishment and the deducted-line block are the same
            // three gates every other billing passes, and a final billing that
            // skipped them would be the one submission nobody checked.
            $billing = $this->billings->submit($milestone, $lines, $by, $periodStart, $periodEnd);

            $this->writeDerivedDeductions($billing, $project, $by);
            $this->writeEnteredDeductions($billing, $deductions, $by);

            return $this->recompute($billing);
        });
    }

    /**
     * Apply a deduction found after the billing was raised.
     *
     * Re-derives the back-charge lines at the same time: anything priced since
     * belongs on the statement, and the alternative is an invoice raised over a
     * charge everybody knew about.
     *
     * @param  array<int, array{type: FinalDeductionType, description: string, amount: string}>  $deductions
     *
     * @throws DomainException when the billing has already been invoiced
     */
    public function applyDeductions(Billing $billing, array $deductions = [], ?User $by = null): Billing
    {
        if ($this->isInvoiced($billing)) {
            // "Before the invoice", read strictly. Afterwards the client holds
            // a document saying a number, and changing the billing underneath
            // it does not change theirs. That correction is a credit note,
            // which this build does not have.
            throw new DomainException(sprintf(
                'Billing %s has already been invoiced. A deduction found now is a credit note against the invoice, not an edit to the billing behind it.',
                $billing->number,
            ));
        }

        $by ??= User::query()->findOrFail($billing->submitted_by_user_id);
        $project = $billing->project()->sole();

        return DB::transaction(function () use ($billing, $deductions, $by, $project): Billing {
            $this->writeDerivedDeductions($billing, $project, $by);
            $this->writeEnteredDeductions($billing, $deductions, $by);

            return $this->recompute($billing);
        });
    }

    /**
     * @return Collection<int, FinalBillingDeduction>
     */
    public function deductionsFor(Billing $billing): Collection
    {
        return $billing->deductions()->with('backCharge')->get();
    }

    public function totalDeductions(Billing $billing): string
    {
        $total = '0.0000';

        foreach ($billing->deductions()->pluck('amount') as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    /**
     * The statement of deductions, as it reads on the final billing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statement(Billing $billing): array
    {
        return $this->deductionsFor($billing)
            ->map(fn (FinalBillingDeduction $deduction): array => [
                'type' => $deduction->type->value,
                'description' => $deduction->description,
                'amount' => (string) $deduction->amount,
                // Where the figure came from. A back-charge cites the document
                // that computed it; a judgement made at final billing cites
                // nothing, and says so rather than citing the billing itself.
                'reference' => $deduction->backCharge?->number,
            ])
            ->all();
    }

    /**
     * Back-charges on the project that this billing does not account for.
     *
     * What the invoice gate reads.
     *
     * @return Collection<int, BackCharge>
     */
    public function unappliedBackCharges(Billing $billing): Collection
    {
        $applied = $billing->deductions()->whereNotNull('back_charge_id')->pluck('back_charge_id')->all();

        return BackCharge::query()
            ->where('project_id', $billing->project_id)
            ->whereNotIn('id', $applied === [] ? [0] : $applied)
            ->orderBy('id')
            ->get();
    }

    public function isFinalMilestone(BillingMilestone $milestone): bool
    {
        $schedule = $milestone->schedule()->sole();

        return $milestone->sequence === (int) $schedule->milestones()->max('sequence');
    }

    public function isFinalBilling(Billing $billing): bool
    {
        $milestone = $billing->milestone()->first();

        return $milestone !== null && $this->isFinalMilestone($milestone);
    }

    public function isInvoiced(Billing $billing): bool
    {
        return SalesInvoice::query()->where('billing_id', $billing->getKey())->exists();
    }

    /**
     * Turnover must be accepted before the works are billed in full.
     *
     * @throws DomainException
     */
    private function assertTurnoverAccepted(Project $project): void
    {
        $pack = $this->turnovers->forProject($project);

        if ($pack === null) {
            throw new DomainException(sprintf(
                'Project %s has no turnover pack. Slide 9 puts turnover and acceptance before final billing, and the pack is what the client accepts.',
                $project->code,
            ));
        }

        if (! $this->turnovers->isAccepted($pack)) {
            throw new DomainException(sprintf(
                'Turnover pack %s has not been accepted by the client. Billing the works in full before they are accepted invites the whole invoice to be disputed.',
                $pack->number,
            ));
        }
    }

    /**
     * The hard predecessor: step 2 before step 4, across two chains.
     *
     * @throws DomainException
     */
    private function assertBackChargesPriced(Project $project): void
    {
        $punchlist = Punchlist::query()->where('project_id', $project->getKey())->first();

        if ($punchlist === null) {
            return;
        }

        $unpriced = $this->backCharges->unpricedItems($punchlist);

        if ($unpriced->isNotEmpty()) {
            throw new DomainException(sprintf(
                'These cleared subcontractor defects have not been priced: %s. What the client owes cannot be settled while what the subcontractor owes is an open question.',
                $unpriced->map(fn ($item): string => sprintf('item %d, %s', $item->item_no, $item->description))->implode('; '),
            ));
        }
    }

    /**
     * One deduction per back-charge on the project, copied rather than entered.
     */
    private function writeDerivedDeductions(Billing $billing, Project $project, User $by): void
    {
        foreach ($this->unappliedBackCharges($billing) as $charge) {
            FinalBillingDeduction::query()->create([
                'billing_id' => $billing->getKey(),
                'type' => FinalDeductionType::BackCharge,
                'back_charge_id' => $charge->getKey(),
                'description' => $charge->description,
                'amount' => (string) $charge->amount,
                'applied_at' => now(),
                'applied_by_user_id' => $by->getKey(),
            ]);
        }
    }

    /**
     * @param  array<int, array{type: FinalDeductionType, description: string, amount: string}>  $deductions
     *
     * @throws DomainException
     */
    private function writeEnteredDeductions(Billing $billing, array $deductions, User $by): void
    {
        foreach ($deductions as $deduction) {
            if ($deduction['type']->isDerived()) {
                throw new DomainException(
                    'A back-charge is computed at punchlist clearing and copied onto the final billing. Entering one by hand is a second opinion about a number that already exists.'
                );
            }

            if (trim($deduction['description']) === '') {
                throw new DomainException(
                    'A deduction needs a description. It is what the client disputes against, and a bare amount off the bill is a query by return of post.'
                );
            }

            if (Money::isZero($deduction['amount']) || bccomp($deduction['amount'], '0', Money::SCALE) < 0) {
                throw new DomainException(sprintf(
                    'A deduction must be a positive amount, got %s. An addition to the bill is a billing line.',
                    $deduction['amount'],
                ));
            }

            FinalBillingDeduction::query()->create([
                'billing_id' => $billing->getKey(),
                'type' => $deduction['type'],
                'back_charge_id' => null,
                'description' => trim($deduction['description']),
                'amount' => $deduction['amount'],
                'applied_at' => now(),
                'applied_by_user_id' => $by->getKey(),
            ]);
        }
    }

    /**
     * Restate the billing's net from its own lines and its own statement.
     *
     * @throws DomainException when the deductions leave nothing to invoice
     */
    private function recompute(Billing $billing): Billing
    {
        $deductions = $this->totalDeductions($billing);
        $gross = (string) $billing->gross_amount;
        $retention = (string) $billing->retention_amount;

        $net = bcsub(bcsub($gross, $retention, Money::SCALE), $deductions, Money::SCALE);

        if (bccomp($net, '0', Money::SCALE) < 0) {
            // The client owes nothing and the company owes them. That is a
            // settlement, and this build has no credit note — an invoice for a
            // negative amount goes into the AR sweep as something to chase.
            throw new DomainException(sprintf(
                'Deductions of %s exceed the %s left to bill after retention. That balance is a settlement with the client, not an invoice.',
                $deductions,
                bcsub($gross, $retention, Money::SCALE),
            ));
        }

        $billing->update([
            'deductions_amount' => $deductions,
            'net_amount' => $net,
        ]);

        return $billing->refresh();
    }
}
