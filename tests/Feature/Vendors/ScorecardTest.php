<?php

use App\Domain\Procurement\InspectionService;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\ThreeWayMatchService;
use App\Domain\Vendors\ScorecardService;
use App\Domain\Vendors\ValidationVerdict;
use App\Domain\Vendors\VendorService;
use App\Domain\Vendors\VendorStatus;
use App\Domain\Vendors\VendorSuspensionReason;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Vendor scorecards — P1-14, closing F12
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md: "The deck disagrees with itself on the scorecard. Slide 4 names
| three dimensions — price, on-time delivery, rejection rate. This slide names
| four, adding completeness of documents. Take slide 5's four as authoritative."
|
| So the fourth dimension is the finding, and it is the one that would have been
| dropped: price, lateness and rejections are all visible on a delivery, while a
| vendor who never sends a delivery receipt costs the company real time and shows
| up nowhere. Rated after every PO, reviewed quarterly — the deck specifies the
| cadence twice, and both are built.
|
| **Every dimension is DERIVED from the chain, not typed in.** A scorecard whose
| numbers a buyer can enter is a record of who likes which supplier. The chain
| already knows when the goods arrived, what inspection rejected, what the
| tabulation said the price should have been, and which documents are on file.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function scorecards(): ScorecardService
{
    return app(ScorecardService::class);
}

/**
 * A delivered order, optionally late and optionally with rejections.
 */
function ratedOrder(string $deliveredOn = '2026-05-12', string $rejected = '0.0000'): array
{
    [$po, $report] = receivedGoods('500.0000');

    PurchaseOrder::mutate(fn () => $po->update(['delivery_date' => '2026-05-15']));

    $accepted = bcsub('500.0000', $rejected, 4);

    app(InspectionService::class)->inspect($report, [
        [
            'receiving_report_line_id' => $report->lines()->sole()->getKey(),
            'quantity_accepted' => $accepted,
            'quantity_rejected' => $rejected,
            'rejection_reason' => bccomp($rejected, '0', 4) > 0 ? 'Hardened in transit.' : null,
        ],
    ], User::factory()->create());

    $report->forceFill(['received_at' => Carbon::parse($deliveredOn)])->saveQuietly();

    return [$po->fresh(), $report->fresh()];
}

it('rates a vendor on all four dimensions after a purchase order', function () {
    // F12 resolved to slide 5's four. Document completeness is the one the deck
    // disagreed about, so it is asserted by name rather than by an overall.
    [$po] = ratedOrder();

    $card = scorecards()->rate($po);

    expect($card->price_score)->not->toBeNull()
        ->and($card->delivery_score)->not->toBeNull()
        ->and($card->quality_score)->not->toBeNull()
        ->and($card->documents_score)->not->toBeNull()
        ->and($card->overall_score)->not->toBeNull();
});

it('scores an on-time, complete, fully accepted delivery at the top', function () {
    [$po] = ratedOrder(deliveredOn: '2026-05-12');

    $card = scorecards()->rate($po);

    expect((string) $card->delivery_score)->toBe('100.00')
        ->and((string) $card->quality_score)->toBe('100.00');
});

it('scores a late delivery down', function () {
    // Delivery date was the 15th; it arrived on the 20th.
    [$po] = ratedOrder(deliveredOn: '2026-05-20');

    $card = scorecards()->rate($po);

    expect((string) $card->delivery_score)->toBe('0.00');
});

it('carries the rejection rate into the quality score', function () {
    // 25 of 500 rejected is 5%, so quality is 95.
    [$po] = ratedOrder(rejected: '25.0000');

    $card = scorecards()->rate($po);

    expect((string) $card->quality_score)->toBe('95.00');
});

it('refuses to rate the same purchase order twice', function () {
    // Two cards for one order is one supplier's quarter counted twice, and the
    // suspension thresholds are counts.
    [$po] = ratedOrder();
    scorecards()->rate($po);

    expect(fn () => scorecards()->rate($po->fresh()))
        ->toThrow(QueryException::class);
});

it('refuses to rate an order that has not been delivered', function () {
    // There is nothing to rate. A card scored on an undelivered order would
    // record an on-time delivery that has not happened.
    $c = canvassed();
    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Portland cement', 'quantity' => '500.0000', 'unit_price' => '249.0000', 'unit' => 'bags'],
    ]);
    PurchaseOrder::mutate(fn () => $po->update(['status' => PurchaseOrderStatus::Approved]));

    expect(fn () => scorecards()->rate($po->fresh()))
        ->toThrow(DomainException::class);
});

it('scores document completeness down when the vendor file is incomplete', function () {
    // THE FOURTH DIMENSION, and the reason F12 says to take slide 5 as
    // authoritative. Everything else about this delivery is perfect: on time,
    // nothing rejected, priced as tabulated. What is missing is paperwork — no
    // invoice ever matched, no validation visit on the accreditation file — and
    // on slide 4's three dimensions this vendor scores full marks while the site
    // chases documents every month.
    [$po] = ratedOrder();

    $card = scorecards()->rate($po);

    expect(bccomp((string) $card->documents_score, '100.00', 2))->toBeLessThan(0)
        ->and(bccomp((string) $card->overall_score, '100.00', 2))->toBeLessThan(0);
});

it('scores document completeness at the top when every document is on file', function () {
    // The same delivery with its paperwork complete: signed for, inspected,
    // invoice matched, and a validation visit on the vendor's file — F15's
    // record, which is what makes the accreditation file complete.
    [$po, $report] = ratedOrder();

    app(VendorService::class)->recordValidationVisit(
        $po->vendor()->sole(),
        Carbon::parse('2026-04-02'),
        ValidationVerdict::Passed,
        User::factory()->create(),
    );

    app(ThreeWayMatchService::class)->match($po, $report, 'INV-SC-1', '124500.0000');

    $card = scorecards()->rate($po->fresh());

    expect((string) $card->documents_score)->toBe('100.00');
});

it('suspends a vendor after two late deliveries in a quarter', function () {
    // PLAN.md §5's rule, and the reason the dimensions are counted rather than
    // admired. One late delivery is weather; two in a quarter is a pattern.
    [$first] = ratedOrder(deliveredOn: '2026-05-20');
    $vendor = $first->vendor()->sole();

    scorecards()->rate($first);

    [$second] = deliveredOrderFor($vendor, deliveredOn: '2026-05-25');
    scorecards()->rate($second);

    scorecards()->reviewQuarter($vendor->fresh(), 2026, 2, User::factory()->create());

    expect($vendor->fresh()->status)->toBe(VendorStatus::Suspended)
        ->and($vendor->fresh()->status_reason)->toBe(VendorSuspensionReason::LateDeliveries);
});

it('does not suspend a vendor for a single late delivery', function () {
    // The boundary. Suspending on one would make the register unusable, and the
    // rule says two.
    [$po] = ratedOrder(deliveredOn: '2026-05-20');
    $vendor = $po->vendor()->sole();

    scorecards()->rate($po);
    scorecards()->reviewQuarter($vendor->fresh(), 2026, 2, User::factory()->create());

    expect($vendor->fresh()->status)->not->toBe(VendorStatus::Suspended);
});

it('suspends a vendor whose rejection rate passes five percent', function () {
    // 30 of 500 is 6%.
    [$po] = ratedOrder(rejected: '30.0000');
    $vendor = $po->vendor()->sole();

    scorecards()->rate($po);
    scorecards()->reviewQuarter($vendor->fresh(), 2026, 2, User::factory()->create());

    expect($vendor->fresh()->status)->toBe(VendorStatus::Suspended)
        ->and($vendor->fresh()->status_reason)->toBe(VendorSuspensionReason::RejectionRate);
});

it('does not suspend a vendor sitting exactly on five percent', function () {
    // 25 of 500 is exactly 5%. The rule is "above 5%", and a threshold that
    // fires ON its own boundary suspends a vendor who met the standard.
    [$po] = ratedOrder(rejected: '25.0000');
    $vendor = $po->vendor()->sole();

    scorecards()->rate($po);
    scorecards()->reviewQuarter($vendor->fresh(), 2026, 2, User::factory()->create());

    expect($vendor->fresh()->status)->not->toBe(VendorStatus::Suspended);
});

it('suspends immediately on an unresolved warranty claim', function () {
    // F11's missing trigger. It does not wait for the quarterly review: the
    // whole point of a warranty claim is that the goods have already failed in
    // service, and the vendor should not be receiving new RFQs meanwhile.
    [$po] = ratedOrder();
    $vendor = $po->vendor()->sole();

    scorecards()->raiseWarrantyClaim($vendor, $po, 'Pump seized after three weeks.', User::factory()->create());

    expect($vendor->fresh()->status)->toBe(VendorStatus::Suspended)
        ->and($vendor->fresh()->status_reason)->toBe(VendorSuspensionReason::WarrantyClaim);
});

it('aggregates a quarter from the cards in it', function () {
    // The deck specifies the cadence twice — rated per PO, reviewed quarterly —
    // so the quarterly figure is an aggregate of the cards, not a second
    // opinion entered separately.
    [$po] = ratedOrder(rejected: '25.0000');
    $vendor = $po->vendor()->sole();
    scorecards()->rate($po);

    $summary = scorecards()->quarterlySummary($vendor->fresh(), 2026, 2);

    expect($summary['cards'])->toBe(1)
        ->and($summary['quality_score'])->toBe('95.00');
});
