<?php

use App\Domain\Documents\DocumentLinker;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\QuoteService;
use App\Domain\Procurement\RfqService;
use App\Domain\Procurement\SoleSourceService;
use App\Domain\Procurement\TabulationService;
use App\Domain\Procurement\UnjustifiedSoleSourceException;
use App\Domain\Procurement\VendorNotEligibleException;
use App\Domain\Requisitions\RequisitionStatus;
use App\Domain\Vendors\VendorService;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Purchase orders — P1-06
|--------------------------------------------------------------------------
|
| PLAN.md §5, stated exactly: "No PO without an approved PR AND a tabulated
| bid."
|
| Both halves, and the AND is the point. An approved PR with no canvass is a
| purchase somebody wanted and nobody priced. A tabulated bid with no approved
| PR is a price for something nobody authorised buying. Either alone looks like
| a complete document set right up until an auditor asks for the other one.
|
| This is the first gate needing TWO predecessors on the handoff spine, which is
| what P0-07's multi-predecessor design was built for and has not been exercised
| until now.
|
| The sole-source path has its own precondition: an RFQ that skipped the canvass
| may only become a PO once its justification has been fully signed at the
| escalated tier. Otherwise the exemption is granted by the act of using it.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');

    defineProcurementTiers();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('raises a purchase order from an approved PR and a tabulated bid', function () {
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Portland cement, 500 bags', 'quantity' => '500.0000', 'unit_price' => '249.0000'],
    ]);

    expect($po->number)->toStartWith('PO-2026-')
        ->and($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($po->vendor_id)->toBe($c['winner']->getKey());

    expectMoney($po->fresh()->total_amount);
    expect($po->fresh()->total_amount)->toBe('124500.0000');
});

it('refuses a purchase order when the requisition is not approved', function () {
    // Half the gate. A tabulated bid with no approved PR is a price for
    // something nobody authorised buying.
    $c = canvassed();
    $c['pr']->update(['status' => RequisitionStatus::Returned]);

    expect(fn () => purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '100.0000'],
    ]))->toThrow(DomainException::class);
});

it('carries both the requisition and the tabulation as predecessors', function () {
    // The AND, on the handoff spine. This is the first document with two
    // predecessors — P0-07 was built for it and had not been exercised.
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]);

    $predecessorIds = app(DocumentLinker::class)
        ->predecessorsOf($po)
        ->map(fn ($model): string => $model::class.':'.$model->getKey())
        ->sort()
        ->values()
        ->all();

    $expected = collect([
        $c['pr']::class.':'.$c['pr']->getKey(),
        $c['tabulation']::class.':'.$c['tabulation']->getKey(),
    ])->sort()->values()->all();

    expect($predecessorIds)->toBe($expected);
});

it('traces a purchase order back to the requisition that started it', function () {
    // What the spine is for: the PO's ancestors include the RFQ it never
    // directly references, two hops back.
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]);

    $ancestorClasses = app(DocumentLinker::class)
        ->ancestorsOf($po)
        ->map(fn ($model): string => $model::class)
        ->unique()
        ->values()
        ->all();

    expect($ancestorClasses)->toContain(Rfq::class)
        ->and($ancestorClasses)->toContain(PurchaseRequisition::class);
});

it('refuses a second purchase order from one tabulation', function () {
    // One canvass, one award. A second PO from the same recommendation would
    // double the commitment against a budget that was checked once.
    $c = canvassed();

    purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]);

    expect(fn () => purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]))->toThrow(QueryException::class);
});

it('refuses a purchase order for a vendor other than the recommended one', function () {
    // Awarding away from the recommendation without a new canvass makes the
    // abstract of canvass decorative.
    $c = canvassed();
    $other = Vendor::factory()->create(['code' => 'VEN-OTHER']);
    app(VendorService::class)->accredit($other, Carbon::parse('2026-05-01'));

    expect(fn () => purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '100.0000'],
    ], vendor: $other->fresh()))->toThrow(DomainException::class);
});

it('refuses a purchase order to a vendor whose accreditation lapsed after the canvass', function () {
    // Eligibility is checked again at award. A canvass run in May and awarded
    // in December is exactly when a certificate expires in between.
    $c = canvassed();

    Carbon::setTestNow('2027-08-01 09:00:00');

    expect(fn () => purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]))->toThrow(VendorNotEligibleException::class);
});

it('refuses a sole-source purchase order with no justification', function () {
    $pr = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Approved]);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'), soleSource: true);

    $vendor = Vendor::factory()->create(['code' => 'VEN-ONLY']);
    app(VendorService::class)->accredit($vendor, Carbon::parse('2026-05-01'));
    app(RfqService::class)->invite($rfq, $vendor->fresh());
    app(RfqService::class)->issue($rfq);

    app(QuoteService::class)->record($rfq->fresh(), $vendor->fresh(), [
        ['description' => 'Proprietary valve', 'quantity' => '1.0000', 'unit_price' => '85000.0000'],
    ]);

    $tabulation = app(TabulationService::class)->tabulate($rfq->fresh());

    expect(fn () => purchaseOrders()->raise($tabulation, [
        ['description' => 'Proprietary valve', 'quantity' => '1.0000', 'unit_price' => '85000.0000'],
    ]))->toThrow(UnjustifiedSoleSourceException::class);
});

it('refuses a sole-source purchase order whose justification is only half signed', function () {
    // THE ONE THAT MATTERS. The justification exists and looks complete on a
    // list; it has one of two required signatures. Awarding here would let the
    // exemption be granted by the act of using it.
    [$tabulation, $justification] = justifiedSoleSource();

    $finance = User::factory()->create();
    $finance->assignRole('finance-manager');

    $step = $justification->approvals()->orderBy('step')->first();
    app(SoleSourceService::class)->approve($justification, $step, $finance);

    expect(fn () => purchaseOrders()->raise($tabulation, [
        ['description' => 'Proprietary valve', 'quantity' => '1.0000', 'unit_price' => '85000.0000'],
    ]))->toThrow(UnjustifiedSoleSourceException::class);
});

it('allows a sole-source purchase order once the justification is fully signed', function () {
    [$tabulation, $justification] = justifiedSoleSource();

    $finance = User::factory()->create();
    $finance->assignRole('finance-manager');
    $director = User::factory()->create();
    $director->assignRole('managing-director');

    $steps = $justification->approvals()->orderBy('step')->get();
    app(SoleSourceService::class)->approve($justification, $steps[0], $finance);
    app(SoleSourceService::class)->approve($justification, $steps[1], $director);

    $po = purchaseOrders()->raise($tabulation, [
        ['description' => 'Proprietary valve', 'quantity' => '1.0000', 'unit_price' => '85000.0000'],
    ]);

    expect($po->sole_source)->toBeTrue();
});

it('routes the purchase order for approval by its own amount', function () {
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]);

    purchaseOrders()->submit($po);

    $steps = $po->fresh()->approvals()->orderBy('step')->get();

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Submitted)
        ->and($steps->pluck('tier')->unique()->all())->toBe([2])
        ->and($steps->pluck('approver_role')->all())->toBe(['project-manager', 'procurement-head']);
});

it('is not approved on one signature of two', function () {
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]);
    purchaseOrders()->submit($po);

    $pm = User::factory()->create();
    $pm->assignRole('project-manager');

    $steps = $po->fresh()->approvals()->orderBy('step')->get();
    purchaseOrders()->approve($po, $steps[0], $pm);

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);
});

it('is approved once every signature is in', function () {
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]);
    purchaseOrders()->submit($po);

    $pm = User::factory()->create();
    $pm->assignRole('project-manager');
    $head = User::factory()->create();
    $head->assignRole('procurement-head');

    $steps = $po->fresh()->approvals()->orderBy('step')->get();
    purchaseOrders()->approve($po, $steps[0], $pm);
    purchaseOrders()->approve($po, $steps[1], $head, 'Award confirmed.');

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

it('refuses a purchase order total set by a direct update', function () {
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]);

    expect(fn () => $po->update(['total_amount' => '1.0000']))->toThrow(DomainException::class);
});

it('refuses a purchase order status set by a direct update', function () {
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000'],
    ]);

    expect(fn () => $po->update(['status' => PurchaseOrderStatus::Approved]))
        ->toThrow(DomainException::class);
});
