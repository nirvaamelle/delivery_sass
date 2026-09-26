<?php

namespace App\Domain\Procurement;

use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\ReceivingReport;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Receiving — PLAN.md §5's "PO before delivery" control.
 *
 * The interesting case is the ordinary one. **Short deliveries are normal**: a
 * truck arrives half full and the site signs for what came. What must not
 * happen is the shortfall disappearing, so three things are true of every
 * receipt:
 *
 *   - the ordered quantity is **snapshotted** onto the receipt, so the document
 *     still reads correctly if the order is revised afterwards;
 *   - the shortfall is **derived at receipt**, not computed on read — the exit
 *     gate asks for it *noted on the DR*, which is a fact about the document;
 *   - the outstanding balance **stays outstanding**, so the three-way match in
 *     P1-11 has something to compare an invoice against. If receiving 400 of
 *     500 closed the line, a full invoice would match a partial delivery.
 *
 * Over-delivery is refused, and refused **cumulatively**: three 200-unit trucks
 * against a 500-unit order each fit on their own.
 */
class ReceivingService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly DocumentLinker $links,
    ) {}

    /**
     * Record a delivery against a purchase order.
     *
     * @param  array<int, array{purchase_order_line_id: int, quantity_received: string, remarks?: string}>  $lines
     *
     * @throws DomainException when the order is not approved, or a line belongs elsewhere
     * @throws OverDeliveryException when the delivery exceeds what remains outstanding
     */
    public function receive(
        PurchaseOrder $order,
        string $deliveryReceiptNumber,
        array $lines,
        ?User $receivedBy = null,
        ?string $remarks = null,
    ): ReceivingReport {
        // PO before delivery. A draft order is a document somebody is still
        // writing, and goods arriving against it were never authorised.
        if (! in_array($order->status, [
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Countersigned,
        ], true)) {
            throw new DomainException(sprintf(
                'Purchase order %s is %s. Goods cannot be received against an order that has not been approved.',
                $order->number,
                $order->status->value,
            ));
        }

        return DB::transaction(function () use ($order, $deliveryReceiptNumber, $lines, $receivedBy, $remarks): ReceivingReport {
            $report = ReceivingReport::mutate(fn (): ReceivingReport => ReceivingReport::query()->create([
                'purchase_order_id' => $order->getKey(),
                'number' => $this->numbering->next('RR'),
                'delivery_receipt_number' => $deliveryReceiptNumber,
                'received_at' => now(),
                'received_by_user_id' => $receivedBy?->getKey(),
                'remarks' => $remarks,
            ]));

            $anyShortfall = false;

            foreach ($lines as $line) {
                $orderLine = PurchaseOrderLine::query()->findOrFail($line['purchase_order_line_id']);

                if ((int) $orderLine->purchase_order_id !== (int) $order->getKey()) {
                    throw new DomainException(sprintf(
                        'Line %d belongs to a different purchase order than %s.',
                        $orderLine->getKey(),
                        $order->number,
                    ));
                }

                $received = $line['quantity_received'];
                $alreadyReceived = (string) $orderLine->quantity_received;
                $ordered = (string) $orderLine->quantity;

                $cumulative = Money::sum($alreadyReceived, $received);

                // Cumulative, not per-receipt. Each of three 200-unit trucks
                // fits a 500-unit order on its own.
                if (Money::greaterThan($cumulative, $ordered)) {
                    throw new OverDeliveryException(sprintf(
                        'Purchase order %s line %d: ordered %s, already received %s, this delivery %s. Over-delivery is stock nobody authorised paying for.',
                        $order->number,
                        $orderLine->getKey(),
                        $ordered,
                        $alreadyReceived,
                        $received,
                    ));
                }

                $short = bcsub($ordered, $cumulative, Money::SCALE);

                $report->lines()->create([
                    'purchase_order_line_id' => $orderLine->getKey(),
                    'quantity_ordered' => $ordered,
                    'quantity_received' => $received,
                    'quantity_short' => $short,
                    'remarks' => $line['remarks'] ?? null,
                ]);

                $orderLine->update(['quantity_received' => $cumulative]);

                if (! Money::isZero($short)) {
                    $anyShortfall = true;
                }
            }

            ReceivingReport::mutate(fn () => $report->update(['has_shortfall' => $anyShortfall]));

            $this->links->link($order, $report);

            return $report->refresh();
        });
    }

    /**
     * How much of the order has still not arrived.
     *
     * This is the number the three-way match reads. A line fully received
     * contributes nothing; a short one keeps its balance until it is either
     * delivered or the order is closed short.
     */
    public function outstandingFor(PurchaseOrder $order): string
    {
        $outstanding = '0';

        foreach ($order->lines()->get() as $line) {
            $remaining = bcsub((string) $line->quantity, (string) $line->quantity_received, Money::SCALE);
            $outstanding = Money::sum($outstanding, $remaining);
        }

        return $outstanding;
    }
}
