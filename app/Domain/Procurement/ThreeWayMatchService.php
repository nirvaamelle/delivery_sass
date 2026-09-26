<?php

namespace App\Domain\Procurement;

use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\PurchaseOrder;
use App\Models\ReceivingReport;
use App\Models\ThreeWayMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The three-way match — PLAN.md §5: "No payment without a three-way match
 * (PO ↔ receiving report ↔ invoice)."
 *
 * Three documents, three questions:
 *
 *   - Did we order it?             The purchase order.
 *   - Did it arrive, and pass?     The receiving report, as accepted by inspection.
 *   - Is this what we were billed? The invoice.
 *
 * **The middle one is the one that gets skipped.** Matching a PO against an
 * invoice is easy and proves almost nothing: both are documents written by the
 * two parties to the transaction, and neither of them says the goods exist. Half
 * a delivery and a full invoice agree with the order perfectly.
 *
 * So the ceiling is the ACCEPTED value, not the delivered one and not the
 * ordered one. If a rejected batch still got paid for, the inspection in P1-09
 * was theatre — it cost the vendor nothing to send bad material. The order total
 * is checked as well, because being billed above the award is its own failure
 * and needs its own message.
 *
 * Nothing is written when the figures disagree. A failed match is a dispute with
 * the supplier, not a document in the chain, and storing it would leave rows
 * that look like matches to anything counting them.
 */
class ThreeWayMatchService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly DocumentLinker $links,
        private readonly InspectionService $inspections,
    ) {}

    /**
     * Match an invoice against an order and a delivery.
     *
     * @throws MatchFailedException when the receipt belongs elsewhere, the goods
     *                              were never inspected, or the invoice exceeds
     *                              what was ordered or what was accepted
     */
    public function match(
        PurchaseOrder $order,
        ReceivingReport $report,
        string $invoiceReference,
        string $invoiceAmount,
        ?User $matchedBy = null,
    ): ThreeWayMatch {
        if ((int) $report->purchase_order_id !== (int) $order->getKey()) {
            throw new MatchFailedException(sprintf(
                'Receiving report %s was raised against a different purchase order than %s. A match across two orders is not a match.',
                $report->number,
                $order->number,
            ));
        }

        $inspection = $report->inspection()->first();

        if ($inspection === null) {
            // Not an oversight to route around: uninspected goods have an
            // accepted quantity of zero, so there is nothing to pay against.
            // Anything else would let a delivery skip inspection entirely by
            // simply never being inspected.
            throw new MatchFailedException(sprintf(
                'Receiving report %s has not been inspected, so nothing on it has been accepted. There is nothing to pay against.',
                $report->number,
            ));
        }

        $ordered = (string) $order->total_amount;
        $accepted = $this->payableValueFor($order, $report);

        if (Money::greaterThan($invoiceAmount, $ordered)) {
            throw new MatchFailedException(sprintf(
                'Invoice %s bills %s against purchase order %s, which awarded %s. Being billed above the award is the case this control exists for.',
                $invoiceReference,
                $invoiceAmount,
                $order->number,
                $ordered,
            ));
        }

        if (Money::greaterThan($invoiceAmount, $accepted)) {
            throw new MatchFailedException(sprintf(
                'Invoice %s bills %s, but inspection accepted %s worth of goods on receiving report %s. The order and the invoice agree with each other; only the delivery knows what arrived.',
                $invoiceReference,
                $invoiceAmount,
                $accepted,
                $report->number,
            ));
        }

        return DB::transaction(function () use ($order, $report, $inspection, $invoiceReference, $invoiceAmount, $ordered, $accepted, $matchedBy): ThreeWayMatch {
            $match = ThreeWayMatch::query()->create([
                'purchase_order_id' => $order->getKey(),
                'receiving_report_id' => $report->getKey(),
                'inspection_id' => $inspection->getKey(),
                'number' => $this->numbering->next('TWM'),
                'invoice_reference' => $invoiceReference,
                'invoice_amount' => $invoiceAmount,
                'ordered_amount' => $ordered,
                'accepted_amount' => $accepted,
                'matched' => true,
                'matched_at' => now(),
                'matched_by_user_id' => $matchedBy?->getKey(),
            ]);

            // Both legs, not just one. The spine is what answers "what is this
            // payment for" three years from now, and a match that named only
            // the order would lose the delivery that justified it.
            $this->links->link($order, $match);
            $this->links->link($report, $match);

            return $match->refresh();
        });
    }

    /**
     * What the accepted goods on this delivery are worth, at the ordered price.
     *
     * Quantity comes from the inspection and price from the purchase order — the
     * two facts neither party can restate on its own. Taking the price off the
     * invoice instead would make the match circular: the invoice would be
     * checked against itself.
     */
    public function payableValueFor(PurchaseOrder $order, ReceivingReport $report): string
    {
        $inspection = $report->inspection()->first();

        if ($inspection === null) {
            return '0.0000';
        }

        $value = '0.0000';

        foreach ($inspection->lines()->with('receivingReportLine.purchaseOrderLine')->get() as $line) {
            $orderLine = $line->receivingReportLine?->purchaseOrderLine;

            if ($orderLine === null || (int) $orderLine->purchase_order_id !== (int) $order->getKey()) {
                continue;
            }

            $value = Money::sum($value, Money::multiply(
                (string) $line->quantity_accepted,
                (string) $orderLine->unit_price,
            ));
        }

        return $value;
    }

    /**
     * What the inspection accepted, as a quantity.
     */
    public function acceptedQuantityFor(ReceivingReport $report): string
    {
        return $this->inspections->acceptedFor($report);
    }
}
