<?php

use App\Domain\Documents\DocumentLinker;
use App\Domain\Procurement\OverDeliveryException;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\ReceivingService;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Delivery receipts and receiving reports — P1-08
|--------------------------------------------------------------------------
|
| PLAN.md §5: "PO before delivery — no receiving report without a PO reference."
| Enforced by a non-nullable foreign key, because a delivery that arrives
| against no order is either somebody else's or a purchase nobody approved, and
| either way it must not enter stock quietly.
|
| The Phase 1 exit gate names a **short delivery noted on the DR**, and that is
| the real work here. Short deliveries are ordinary — a truck comes half full
| and the site signs for what arrived. What must not happen is the shortfall
| being lost: the receipt records what was ordered AND what came, the variance
| is derived rather than typed, and the outstanding balance stays outstanding so
| the three-way match in P1-11 has something to compare against.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function receiving(): ReceivingService
{
    return app(ReceivingService::class);
}

/**
 * An approved purchase order for 500 units at ₱249.
 */
function approvedOrder(string $quantity = '500.0000'): PurchaseOrder
{
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Portland cement', 'quantity' => $quantity, 'unit_price' => '249.0000', 'unit' => 'bags'],
    ]);

    // Approved without walking the signatures: this suite is about receiving,
    // and P1-06 already proves the approval path.
    PurchaseOrder::mutate(fn () => $po->update(['status' => PurchaseOrderStatus::Approved]));

    return $po->fresh();
}

it('receives a delivery in full against a purchase order', function () {
    $po = approvedOrder();
    $line = $po->lines()->sole();

    $report = receiving()->receive($po, 'DR-88213', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '500.0000'],
    ], User::factory()->create());

    expect($report->number)->toStartWith('RR-2026-')
        ->and($report->delivery_receipt_number)->toBe('DR-88213')
        ->and($report->has_shortfall)->toBeFalse()
        ->and($line->fresh()->quantity_received)->toBe('500.0000');
});

it('notes a short delivery on the receiving report', function () {
    // The Phase 1 exit gate's own words. 400 of 500 bags arrive; the site signs
    // for 400 and the shortfall is recorded rather than rounded away.
    $po = approvedOrder();
    $line = $po->lines()->sole();

    $report = receiving()->receive($po, 'DR-88214', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '400.0000'],
    ], User::factory()->create());

    expect($report->has_shortfall)->toBeTrue();

    $reportLine = $report->lines()->sole();

    expect($reportLine->quantity_ordered)->toBe('500.0000')
        ->and($reportLine->quantity_received)->toBe('400.0000')
        ->and($reportLine->quantity_short)->toBe('100.0000');
});

it('keeps the shortfall outstanding against the order', function () {
    // The balance is what the three-way match compares against. If receiving
    // 400 of 500 closed the line, the match would pass on a full invoice.
    $po = approvedOrder();
    $line = $po->lines()->sole();

    receiving()->receive($po, 'DR-1', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '400.0000'],
    ], User::factory()->create());

    expect(receiving()->outstandingFor($po->fresh()))->toBe('100.0000');
});

it('accumulates partial deliveries across receipts', function () {
    // Two trucks, one order. The second receipt has to know what the first one
    // already brought.
    $po = approvedOrder();
    $line = $po->lines()->sole();

    receiving()->receive($po, 'DR-1', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '300.0000'],
    ], User::factory()->create());

    receiving()->receive($po, 'DR-2', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '200.0000'],
    ], User::factory()->create());

    expect($line->fresh()->quantity_received)->toBe('500.0000')
        ->and(receiving()->outstandingFor($po->fresh()))->toBe('0.0000');
});

it('refuses a delivery of more than was ordered', function () {
    // Over-delivery is not a windfall — it is stock nobody authorised paying
    // for, and accepting it silently makes the PO a suggestion.
    $po = approvedOrder();
    $line = $po->lines()->sole();

    expect(fn () => receiving()->receive($po, 'DR-1', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '600.0000'],
    ], User::factory()->create()))->toThrow(OverDeliveryException::class);
});

it('refuses a cumulative over-delivery across receipts', function () {
    // Each receipt fits; together they exceed the order. Checking only the
    // current receipt would let three 200-bag trucks deliver 600 against 500.
    $po = approvedOrder();
    $line = $po->lines()->sole();

    receiving()->receive($po, 'DR-1', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '300.0000'],
    ], User::factory()->create());

    expect(fn () => receiving()->receive($po, 'DR-2', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '300.0000'],
    ], User::factory()->create()))->toThrow(OverDeliveryException::class);
});

it('refuses a receiving report against a purchase order that is not approved', function () {
    // PO before delivery. A draft order is a document somebody is still
    // writing.
    $c = canvassed();
    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '500.0000', 'unit_price' => '249.0000'],
    ]);

    expect(fn () => receiving()->receive($po, 'DR-1', [
        ['purchase_order_line_id' => $po->lines()->sole()->getKey(), 'quantity_received' => '1.0000'],
    ], User::factory()->create()))->toThrow(DomainException::class);
});

it('refuses a receiving report with no PO reference at the database', function () {
    // PLAN.md §5's wording, enforced by the column rather than the service, so
    // an importer cannot write one either.
    expect(fn () => DB::table('receiving_reports')->insert([
        'purchase_order_id' => null,
        'number' => 'RR-2026-99999',
        'delivery_receipt_number' => 'DR-X',
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses a line belonging to a different purchase order', function () {
    $first = approvedOrder();
    $second = approvedOrder();

    expect(fn () => receiving()->receive($first, 'DR-1', [
        ['purchase_order_line_id' => $second->lines()->sole()->getKey(), 'quantity_received' => '1.0000'],
    ], User::factory()->create()))->toThrow(DomainException::class);
});

it('refuses a duplicate delivery receipt number on one order', function () {
    // The DR number is the supplier's own reference. Two receipts quoting one
    // DR is either a double-count or a typo, and both need looking at.
    $po = approvedOrder();
    $line = $po->lines()->sole();

    receiving()->receive($po, 'DR-SAME', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '100.0000'],
    ], User::factory()->create());

    expect(fn () => receiving()->receive($po, 'DR-SAME', [
        ['purchase_order_line_id' => $line->getKey(), 'quantity_received' => '100.0000'],
    ], User::factory()->create()))->toThrow(QueryException::class);
});

it('links the receiving report to its purchase order on the handoff spine', function () {
    $po = approvedOrder();

    $report = receiving()->receive($po, 'DR-1', [
        ['purchase_order_line_id' => $po->lines()->sole()->getKey(), 'quantity_received' => '500.0000'],
    ], User::factory()->create());

    $predecessors = app(DocumentLinker::class)->predecessorsOf($report);

    expect($predecessors)->toHaveCount(1)
        ->and($predecessors->first()->getKey())->toBe($po->getKey());
});

it('refuses a received quantity changed by a direct update', function () {
    $po = approvedOrder();

    $report = receiving()->receive($po, 'DR-1', [
        ['purchase_order_line_id' => $po->lines()->sole()->getKey(), 'quantity_received' => '400.0000'],
    ], User::factory()->create());

    expect(fn () => $report->update(['has_shortfall' => false]))
        ->toThrow(DomainException::class);
});
