<?php

namespace App\Domain\Procurement;

use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Requisitions\RequisitionStatus;
use App\Domain\Support\Money;
use App\Domain\Vendors\VendorService;
use App\Models\Approval;
use App\Models\BidTabulation;
use App\Models\PurchaseOrder;
use App\Models\SoleSourceJustification;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Purchase orders — PLAN.md §5's central procurement gate.
 *
 *   "No PO without an approved PR AND a tabulated bid."
 *
 * The AND is the whole rule. An approved PR with no canvass is a purchase
 * somebody wanted and nobody priced; a tabulated bid with no approved PR is a
 * price for something nobody authorised buying. Either alone looks like a
 * complete document set right up until an auditor asks for the other one, which
 * is why both are non-nullable foreign keys rather than a service-layer habit.
 *
 * Three further checks run at award, each guarding a gap that opens between the
 * canvass and the order:
 *
 *   - **The vendor must still be eligible.** A canvass run in May and awarded in
 *     December is exactly when an accreditation expires in between.
 *   - **The award must follow the recommendation.** Awarding elsewhere without a
 *     new canvass makes the abstract of canvass decorative.
 *   - **A sole source needs its justification fully signed.** The exemption is
 *     granted by the escalated approval, not by the act of using it.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly ApprovalRouter $approvals,
        private readonly DocumentLinker $links,
        private readonly VendorService $vendors,
    ) {}

    /**
     * Raise a draft purchase order against a tabulated bid.
     *
     * @param  array<int, array{description: string, quantity: string, unit_price: string, cost_code_id?: int, unit?: string}>  $lines
     *
     * @throws DomainException when the requisition is unapproved, or the vendor is not the recommended one
     * @throws VendorNotEligibleException when the vendor's accreditation is no longer current
     * @throws UnjustifiedSoleSourceException when a sole source has no fully approved justification
     * @throws InvalidArgumentException when the order has no lines
     */
    public function raise(BidTabulation $tabulation, array $lines, ?Vendor $vendor = null): PurchaseOrder
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A purchase order with no lines orders nothing.');
        }

        $rfq = $tabulation->rfq()->sole();
        $requisition = $rfq->requisition()->sole();

        // Half the gate: the PR must be approved.
        if ($requisition->status !== RequisitionStatus::Approved) {
            throw new DomainException(sprintf(
                'Requisition %s is %s. A purchase order needs an approved requisition — otherwise this is a price for something nobody authorised buying.',
                $requisition->number,
                $requisition->status->value,
            ));
        }

        $recommended = $tabulation->recommendedVendor()->sole();
        $vendor ??= $recommended;

        if ((int) $vendor->getKey() !== (int) $recommended->getKey()) {
            throw new DomainException(sprintf(
                'Tabulation %s recommends %s. Awarding to %s instead needs a new canvass, or the abstract of canvass is decorative.',
                $tabulation->number,
                $recommended->code,
                $vendor->code,
            ));
        }

        // Re-checked at award, not merely at invitation. Time passes between a
        // canvass and an order, and certificates expire in it.
        if (! $this->vendors->isAccredited($vendor)) {
            throw new VendorNotEligibleException(sprintf(
                'Vendor %s is no longer accredited, so it cannot be awarded purchase order work today even though it was eligible when canvassed.',
                $vendor->code,
            ));
        }

        if ($rfq->sole_source) {
            $this->assertSoleSourceJustified($rfq->getKey(), $tabulation->number);
        }

        return DB::transaction(function () use ($tabulation, $requisition, $rfq, $vendor, $lines): PurchaseOrder {
            $order = PurchaseOrder::mutate(fn (): PurchaseOrder => PurchaseOrder::query()->create([
                'purchase_requisition_id' => $requisition->getKey(),
                'bid_tabulation_id' => $tabulation->getKey(),
                'vendor_id' => $vendor->getKey(),
                'project_id' => $requisition->project_id,
                'number' => $this->numbering->next('PO'),
                'status' => PurchaseOrderStatus::Draft,
                'total_amount' => '0.0000',
                'sole_source' => $rfq->sole_source,
            ]));

            $total = '0';
            $defaultCostCode = $requisition->lines()->value('cost_code_id');

            foreach ($lines as $line) {
                $lineTotal = Money::multiply($line['quantity'], $line['unit_price']);

                $order->lines()->create([
                    // Falls back to the requisition's cost code: the PR is what
                    // the budget was checked against, so charging the order
                    // somewhere else would spend a budget nobody checked.
                    'cost_code_id' => $line['cost_code_id'] ?? $defaultCostCode,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $lineTotal,
                    'unit' => $line['unit'] ?? null,
                ]);

                $total = Money::sum($total, $lineTotal);
            }

            PurchaseOrder::mutate(fn () => $order->update(['total_amount' => Money::sum($total)]));

            // Both predecessors on the handoff spine — the first document in
            // the build with two, which is what PLAN.md §1's "the document
            // before it" means when a gate has two halves.
            $this->links->link($requisition, $order);
            $this->links->link($tabulation, $order);

            return $order->refresh();
        });
    }

    /**
     * Submit the order for approval, routed by its own amount.
     */
    public function submit(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatus::Draft) {
            throw new DomainException(sprintf(
                'Purchase order %s is %s, not draft.',
                $order->number,
                $order->status->value,
            ));
        }

        return DB::transaction(function () use ($order): PurchaseOrder {
            PurchaseOrder::mutate(fn () => $order->update([
                'status' => PurchaseOrderStatus::Submitted,
                'submitted_at' => now(),
            ]));

            $this->approvals->request($order, 'purchase_order', (string) $order->total_amount);

            return $order->refresh();
        });
    }

    /**
     * Record one signature. The order is approved only when all are in.
     */
    public function approve(PurchaseOrder $order, Approval $step, User $approver, ?string $remarks = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $step, $approver, $remarks): PurchaseOrder {
            $this->approvals->approve($step, $approver, $remarks);

            $outstanding = $order->approvals()
                ->where('decision', ApprovalDecision::Pending)
                ->exists();

            if (! $outstanding) {
                PurchaseOrder::mutate(fn () => $order->update([
                    'status' => PurchaseOrderStatus::Approved,
                ]));
            }

            return $order->refresh();
        });
    }

    /**
     * Record the vendor's countersignature — F2's gate condition.
     *
     * Approved and countersigned are two different facts about one document:
     * approved is the company deciding to buy, countersigned is the supplier
     * agreeing to sell. Slide 3 gates mobilization on the second, which is why
     * this is a state on the order rather than an attachment somebody files.
     *
     * The signatory's name is stored, not merely the fact of a signature. The
     * question asked in a dispute is who accepted the order, and a boolean
     * cannot answer it.
     *
     * @throws DomainException when the order was never approved, or is already
     *                         countersigned
     */
    public function countersign(
        PurchaseOrder $order,
        CarbonInterface $countersignedAt,
        string $signatory,
    ): PurchaseOrder {
        if ($order->status !== PurchaseOrderStatus::Approved) {
            // There is nothing for a vendor to accept until the company has
            // decided. Countersigning a draft would let mobilization open on an
            // order that never went through the authority matrix at all.
            throw new DomainException(sprintf(
                'Purchase order %s is %s. A vendor cannot countersign an order the company has not approved.',
                $order->number,
                $order->status->value,
            ));
        }

        if (trim($signatory) === '') {
            throw new DomainException(
                'A countersignature needs the name of whoever signed. "Somebody agreed" is not something a dispute can be settled with.'
            );
        }

        PurchaseOrder::mutate(fn () => $order->update([
            'status' => PurchaseOrderStatus::Countersigned,
            'countersigned_at' => $countersignedAt,
            'countersigned_by' => $signatory,
        ]));

        return $order->refresh();
    }

    /**
     * Send the order back with a reason.
     */
    public function returnForRevision(PurchaseOrder $order, Approval $step, User $approver, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $step, $approver, $reason): PurchaseOrder {
            $this->approvals->returnForRevision($step, $approver, $reason);

            PurchaseOrder::mutate(fn () => $order->update([
                'status' => PurchaseOrderStatus::Returned,
            ]));

            return $order->refresh();
        });
    }

    /**
     * @throws UnjustifiedSoleSourceException
     */
    private function assertSoleSourceJustified(int $rfqId, string $tabulationNumber): void
    {
        $justification = SoleSourceJustification::query()
            ->where('rfq_id', $rfqId)
            ->first();

        if ($justification === null) {
            throw new UnjustifiedSoleSourceException(sprintf(
                'Tabulation %s is a sole source with no written justification on file. PLAN.md §5 requires one, approved one level above.',
                $tabulationNumber,
            ));
        }

        // Fully signed, not merely present. A half-signed justification looks
        // complete on a list, and awarding against it would let the exemption
        // be granted by the act of using it.
        if (! $justification->isApproved()) {
            throw new UnjustifiedSoleSourceException(sprintf(
                'Sole-source justification %s is not fully approved at tier %d. The exemption comes from the escalated approval, not from having filed the paperwork.',
                $justification->number,
                $justification->tier,
            ));
        }
    }
}
