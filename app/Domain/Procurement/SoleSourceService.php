<?php

namespace App\Domain\Procurement;

use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Approvals\ApproverLacksAuthorityException;
use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Models\Approval;
use App\Models\Rfq;
use App\Models\SoleSourceJustification;
use App\Models\User;
use App\Models\Vendor;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Sole-source justification — PLAN.md §5.
 *
 *   "Three quotes minimum before award; sole source needs written justification
 *    approved one level up."
 *
 * P1-03 and P1-04 let a sole-source RFQ skip the three-quote minimum. This is
 * the price of that exemption, and without it the exemption is simply a way
 * around the control: declare sole source, invite one vendor, award. The
 * justification is what turns that into a decision somebody signed for.
 *
 * **The escalation is computed from the AMOUNT**, not from whoever raised the
 * document. A ₱2m sole source sits in tier 3 and is approved at tier 4 — if the
 * escalation were relative to the raiser, the same purchase would need
 * different approval depending on who typed it in, and a junior raising
 * everything would lower the bar for the whole company.
 *
 * PLACEHOLDER: Part D item 1. The bands the escalation is computed against are
 * still the build's own, so this is proven in shape and provisional in
 * threshold. P1-17 re-tests it when the real limits land.
 */
class SoleSourceService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly ApprovalRouter $approvals,
        private readonly DocumentLinker $links,
    ) {}

    /**
     * Record the written justification and route it one tier up.
     *
     * @throws DomainException when the RFQ is not a declared sole source
     * @throws JustificationRequiredException when the narrative is blank
     */
    public function justify(
        Rfq $rfq,
        Vendor $vendor,
        SoleSourceReason $reason,
        string $narrative,
        string $amount,
    ): SoleSourceJustification {
        if (! $rfq->sole_source) {
            throw new DomainException(sprintf(
                'RFQ %s is not a sole source, so it has nothing to justify — it is held to the three-quote minimum instead.',
                $rfq->number,
            ));
        }

        if (trim($narrative) === '') {
            throw new JustificationRequiredException(
                'A sole-source justification must be written. A reason code with no narrative records that somebody chose from a dropdown, not why the canvass was skipped.'
            );
        }

        // One level above what the amount alone would require. routeFor() has
        // done this since P0-12 and returns the top tier unchanged when there is
        // no level above — there is no fifth tier to escalate into.
        $escalated = $this->approvals->routeFor('purchase_order', $amount, soleSource: true);

        return DB::transaction(function () use ($rfq, $vendor, $reason, $narrative, $amount, $escalated): SoleSourceJustification {
            $justification = SoleSourceJustification::mutate(
                fn (): SoleSourceJustification => SoleSourceJustification::query()->create([
                    'rfq_id' => $rfq->getKey(),
                    'vendor_id' => $vendor->getKey(),
                    'number' => $this->numbering->next('SSJ'),
                    'reason' => $reason,
                    'narrative' => trim($narrative),
                    'amount' => $amount,
                    'tier' => $escalated->tier,
                ])
            );

            // Routed at the escalated tier's own amount floor, so the approval
            // steps carry that tier's roles rather than the amount's.
            $this->approvals->request(
                $justification,
                'purchase_order',
                (string) $escalated->min_amount,
            );

            $this->links->link($rfq, $justification);

            return $justification->refresh();
        });
    }

    /**
     * Record one signature at the escalated tier.
     *
     * Authority is checked by ApprovalRouter, which is what makes "one level up"
     * real: the tier below cannot sign its own exemption.
     *
     * @throws ApproverLacksAuthorityException
     */
    public function approve(
        SoleSourceJustification $justification,
        Approval $step,
        User $approver,
        ?string $remarks = null,
    ): SoleSourceJustification {
        return DB::transaction(function () use ($justification, $step, $approver, $remarks): SoleSourceJustification {
            $this->approvals->approve($step, $approver, $remarks);

            return $justification->refresh();
        });
    }

    /**
     * Send the justification back, with the reason attached.
     *
     * @throws ApproverLacksAuthorityException
     */
    public function returnForRevision(
        SoleSourceJustification $justification,
        Approval $step,
        User $approver,
        string $reason,
    ): SoleSourceJustification {
        return DB::transaction(function () use ($justification, $step, $approver, $reason): SoleSourceJustification {
            $this->approvals->returnForRevision($step, $approver, $reason);

            return $justification->refresh();
        });
    }
}
