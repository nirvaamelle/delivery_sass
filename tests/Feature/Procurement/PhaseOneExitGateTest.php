<?php

use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Documents\DocumentLinker;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\MaterialCostPoster;
use App\Domain\Procurement\MatchFailedException;
use App\Domain\Procurement\PayablesService;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\ReceivingService;
use App\Domain\Procurement\RfqService;
use App\Domain\Procurement\StockService;
use App\Domain\Procurement\ThreeWayMatchService;
use App\Domain\Procurement\VendorNotEligibleException;
use App\Domain\Requisitions\RequisitionStatus;
use App\Domain\Vendors\VendorService;
use App\Models\ProjectCostLedgerEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Phase 1 exit gate — P1-18
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Part C states it as behaviour, not as a checklist of code:
|
|   "One full cycle: PR through AP voucher, with a sole-source purchase
|    escalating one level, an expired-accreditation vendor rejected from an
|    RFQ, and a short delivery noted on the DR. Material cost appears in the
|    ledger."
|
| Five clauses, and **three of them are refusals**. That is the shape of the
| whole system and the reason the deck exists: the argument is not that a
| purchase order can be raised — any spreadsheet can raise one — it is that the
| wrong purchase order cannot.
|
| Each clause below is one test, named for the clause it demonstrates, so a
| failure says which part of the gate broke rather than that "the gate failed".
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');
    // Both document types, because the gate crosses them: a requisition routes
    // by amount, and the sole-source escalation is a question about the order.
    defineProcurementTiers('purchase_requisition');
    defineProcurementTiers('purchase_order');
});

afterEach(fn () => Carbon::setTestNow());

it('carries one purchase through from requisition to AP voucher', function () {
    // CLAUSE 1. The full cycle, every document created by the service that owns
    // its gates — nothing in this test inserts a row directly, so a chain that
    // the application would have refused cannot be assembled here either.
    [$po, $report] = acceptedGoods('500.0000');

    expect($po->status)->toBe(PurchaseOrderStatus::Approved);

    // The requisition behind it was approved before the RFQ could be opened.
    $requisition = $po->requisition()->sole();
    expect($requisition->number)->toStartWith('PR-2026-');

    $match = app(ThreeWayMatchService::class)->match($po, $report, 'INV-GATE-1', '124500.0000');
    $voucher = app(PayablesService::class)->raise($match, 'goods');

    expect($voucher->number)->toStartWith('APV-2026-')
        ->and($voucher->fresh()->gross_amount)->toBe('124500.0000')
        // 1% on goods.
        ->and($voucher->fresh()->withholding_amount)->toBe('1245.0000')
        ->and($voucher->fresh()->net_amount)->toBe('123255.0000');

    // And the whole chain is traceable backwards along the handoff spine, which
    // is what PLAN.md §1 asks every document to carry.
    $ancestors = app(DocumentLinker::class)
        ->ancestorsOf($voucher)
        ->map(fn ($model): string => $model::class)
        ->all();

    expect($ancestors)->toContain(PurchaseOrder::class)
        ->and($ancestors)->toContain(PurchaseRequisition::class);
});

it('escalates a sole-source purchase one approval level above its band', function () {
    // CLAUSE 2. The exception the deck is most worried about: a purchase with no
    // canvass behind it, approved by the same person who would have approved an
    // ordinary one. 85,000 sits in tier 2; sole source routes it to tier 3.
    [$tabulation, $justification] = justifiedSoleSource();

    $router = app(ApprovalRouter::class);

    $ordinary = $router->routeFor('purchase_order', '85000.0000');
    $escalated = $router->routeFor('purchase_order', '85000.0000', soleSource: true);

    expect($escalated->tier)->toBe($ordinary->tier + 1)
        // The escalation is worth nothing without the written reason behind it:
        // a tier higher is a different signature on the same undocumented
        // decision.
        ->and($justification->reason)->not->toBeNull()
        ->and($justification->narrative)->not->toBeEmpty()
        ->and($tabulation->quotes_compared)->toBe(1);
});

it('rejects a vendor with an expired accreditation from an RFQ', function () {
    // CLAUSE 3, and the refusal that gives the register its point. The vendor is
    // not suspended and not removed — the file simply lapsed, which is the case
    // that passes an eyeball check every time.
    $requisition = PurchaseRequisition::factory()->create([
        'status' => RequisitionStatus::Approved,
    ]);

    $rfq = app(RfqService::class)->open($requisition, Carbon::parse('2026-05-20'));

    $lapsed = Vendor::factory()->create(['code' => 'VEN-LAPSED']);
    app(VendorService::class)->accredit($lapsed, Carbon::parse('2025-01-01'));

    expect(fn () => app(RfqService::class)->invite($rfq, $lapsed->fresh()))
        ->toThrow(VendorNotEligibleException::class);

    // Refused at the point the mistake is made, not at issue: an expired vendor
    // sitting on a draft looking invited is discovered at the worst moment.
    expect($rfq->fresh()->recipients()->count())->toBe(0);
});

it('notes a short delivery on the receiving report', function () {
    // CLAUSE 4. 400 of 500 arrived. The shortfall is derived AT receipt and
    // stored on the document, because the gate asks for it noted on the DR —
    // that is a fact about the document, not a calculation a report does later.
    [$po, $report] = shortDelivery('500.0000', '400.0000');

    expect($report->has_shortfall)->toBeTrue()
        ->and((string) $report->lines()->sole()->quantity_short)->toBe('100.0000');

    // And the outstanding 100 stays outstanding against the order rather than
    // the line closing short.
    expect(app(ReceivingService::class)->outstandingFor($po->fresh()))->toBe('100.0000');
});

it('puts material cost in the ledger', function () {
    // CLAUSE 5, the one that closes the circle: PLAN.md §1 says every process
    // ends in project_cost_ledger, and until P1-15 the procurement chain
    // stopped one document short of it.
    [$card, $project, $costCode] = stockedGoods();

    $issuance = app(StockService::class)->issue($card, '200.0000', $costCode->getKey(), User::factory()->create());
    $entry = app(MaterialCostPoster::class)->post($issuance->fresh());

    expect($entry->category)->toBe(LedgerCategory::Material)
        ->and($entry->amount)->toBe('49800.0000')
        ->and($entry->project_code)->toBe($project->code)
        ->and($entry->cost_code)->toBe($costCode->code);

    // The row is in the ledger the P&L is assembled from, not merely returned
    // by the service that wrote it.
    expect(ProjectCostLedgerEntry::query()
        ->where('document_number', $issuance->number)
        ->where('category', LedgerCategory::Material)
        ->exists())->toBeTrue();
});

it('refuses to pay for a delivery that never passed inspection', function () {
    // Not one of the five clauses, and included deliberately. The gate as
    // written could be satisfied by a system that pays for anything once a
    // receipt exists, and the control PLAN.md §5 actually specifies is that
    // payment follows what was ACCEPTED.
    [$po, $report] = receivedGoods('500.0000');

    expect(fn () => app(ThreeWayMatchService::class)->match($po->fresh(), $report->fresh(), 'INV-GATE-2', '124500.0000'))
        ->toThrow(MatchFailedException::class);
});

it('pays only for what inspection accepted, on a short and partly rejected delivery', function () {
    // The whole gate in one document set, which is what a real month looks like:
    // 480 of 500 arrived, 20 of those were rejected, and 460 is what may be
    // paid for. A PO-to-invoice check would have passed the full 124,500.
    [$po, $report] = shortDelivery('500.0000', '480.0000');

    // Re-inspect: the fixture accepted everything, so reject twenty of it.
    $report->inspection()->sole()->lines()->sole()->update([
        'quantity_accepted' => '460.0000',
        'quantity_rejected' => '20.0000',
        'rejection_reason' => 'Hardened in transit.',
    ]);

    $matches = app(ThreeWayMatchService::class);

    expect($matches->payableValueFor($po->fresh(), $report->fresh()))->toBe('114540.0000');

    expect(fn () => $matches->match($po->fresh(), $report->fresh(), 'INV-GATE-3', '124500.0000'))
        ->toThrow(MatchFailedException::class);

    $match = $matches->match($po->fresh(), $report->fresh(), 'INV-GATE-4', '114540.0000');

    expect($match->matched)->toBeTrue();
});
