<?php

use App\Domain\Documents\DocumentLinker;
use App\Domain\Procurement\RfqService;
use App\Domain\Procurement\RfqStatus;
use App\Domain\Procurement\VendorNotEligibleException;
use App\Domain\Requisitions\RequisitionStatus;
use App\Domain\Vendors\VendorService;
use App\Domain\Vendors\VendorSuspensionReason;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Requests for quotation — P1-03
|--------------------------------------------------------------------------
|
| PLAN.md §5:
|
|   "Accreditation expires at 12 months; expired vendors cannot receive an RFQ."
|   "Three quotes minimum before award."
|
| The first of those fires here. P1-01 built the evidence — who is accredited,
| who is suspended, whose certificate has lapsed — and this is where refusing to
| send them anything actually happens.
|
| The gate is on ADDING a recipient, not on issuing the RFQ. Checking only at
| issue time means an expired vendor sits on the draft looking invited, and
| whoever assembled the list finds out at the last moment. Checking per recipient
| refuses it at the point the mistake is made.
|
| An RFQ also cannot exist without an approved requisition behind it. The deck's
| chain is PR → RFQ → canvass, and an RFQ with no PR is a purchase nobody asked
| for.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function rfqs(): RfqService
{
    return app(RfqService::class);
}

function accreditedVendor(string $code): Vendor
{
    $vendor = Vendor::factory()->create(['code' => $code]);
    app(VendorService::class)->accredit($vendor, Carbon::parse('2026-05-01'));

    return $vendor->fresh();
}

/**
 * An approved requisition, which is what an RFQ has to hang off.
 */
function approvedRequisition(): PurchaseRequisition
{
    return PurchaseRequisition::factory()->create([
        'status' => RequisitionStatus::Approved,
    ]);
}

it('opens an RFQ against an approved requisition', function () {
    $pr = approvedRequisition();

    $rfq = rfqs()->open($pr, Carbon::parse('2026-05-20'));

    expect($rfq->number)->toStartWith('RFQ-2026-')
        ->and($rfq->status)->toBe(RfqStatus::Draft)
        ->and($rfq->purchase_requisition_id)->toBe($pr->getKey());
});

it('refuses an RFQ against a requisition that is not approved', function () {
    // PR → RFQ → canvass. An RFQ with no approved PR behind it is a purchase
    // nobody asked for, and the vendor is being invited to quote on it.
    $pr = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Draft]);

    expect(fn () => rfqs()->open($pr, Carbon::parse('2026-05-20')))
        ->toThrow(DomainException::class);
});

it('invites an accredited vendor', function () {
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));
    $vendor = accreditedVendor('VEN-GOOD');

    $recipient = rfqs()->invite($rfq, $vendor);

    expect($recipient->vendor_id)->toBe($vendor->getKey())
        ->and($rfq->recipients()->count())->toBe(1);
});

it('refuses to invite a vendor whose accreditation has expired', function () {
    // THE CONTROL. The vendor is real, was properly accredited once, and the
    // certificate simply ran out — which is precisely the case that slips past
    // a human checking a list.
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    $expired = Vendor::factory()->create(['code' => 'VEN-EXPIRED']);
    app(VendorService::class)->accredit($expired, Carbon::parse('2024-01-01'));

    expect(fn () => rfqs()->invite($rfq, $expired->fresh()))
        ->toThrow(VendorNotEligibleException::class);

    expect($rfq->recipients()->count())->toBe(0);
});

it('refuses to invite a suspended vendor', function () {
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    $suspended = accreditedVendor('VEN-SUSPENDED');
    app(VendorService::class)->suspend(
        $suspended,
        VendorSuspensionReason::LateDeliveries,
        User::factory()->create(),
    );

    expect(fn () => rfqs()->invite($rfq, $suspended->fresh()))
        ->toThrow(VendorNotEligibleException::class);
});

it('refuses to invite a vendor that was never accredited', function () {
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    expect(fn () => rfqs()->invite($rfq, Vendor::factory()->create()))
        ->toThrow(VendorNotEligibleException::class);
});

it('refuses to invite the same vendor twice', function () {
    // A duplicate recipient inflates the count of quotes sought, which is the
    // number the three-quote minimum is measured against.
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));
    $vendor = accreditedVendor('VEN-GOOD');

    rfqs()->invite($rfq, $vendor);

    expect(fn () => rfqs()->invite($rfq, $vendor))->toThrow(QueryException::class);
});

it('refuses to issue an RFQ to fewer than three vendors', function () {
    // PLAN.md §5: three quotes minimum before award. Enforced at issue rather
    // than at award, because sending to two and hoping is how a canvass ends up
    // needing a sole-source justification written after the fact.
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    rfqs()->invite($rfq, accreditedVendor('VEN-A'));
    rfqs()->invite($rfq, accreditedVendor('VEN-B'));

    expect(fn () => rfqs()->issue($rfq))->toThrow(DomainException::class);
    expect($rfq->fresh()->status)->toBe(RfqStatus::Draft);
});

it('issues an RFQ once three vendors are invited', function () {
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    foreach (['VEN-A', 'VEN-B', 'VEN-C'] as $code) {
        rfqs()->invite($rfq, accreditedVendor($code));
    }

    rfqs()->issue($rfq);

    expect($rfq->fresh()->status)->toBe(RfqStatus::Issued)
        ->and($rfq->fresh()->issued_at)->not->toBeNull();
});

it('allows a sole-source RFQ to issue to one vendor', function () {
    // The documented exception. Sole source is not "fewer than three by
    // accident" — it is a deliberate decision that carries a written
    // justification approved one level up, which P1-05 builds.
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'), soleSource: true);

    rfqs()->invite($rfq, accreditedVendor('VEN-ONLY'));

    rfqs()->issue($rfq);

    expect($rfq->fresh()->status)->toBe(RfqStatus::Issued)
        ->and($rfq->fresh()->sole_source)->toBeTrue();
});

it('links the RFQ to its requisition on the handoff spine', function () {
    // PLAN.md §1: every document carries the reference of the document before
    // it. P0-07 built the spine; this is the first real pair of documents on it.
    $pr = approvedRequisition();
    $rfq = rfqs()->open($pr, Carbon::parse('2026-05-20'));

    $predecessors = app(DocumentLinker::class)->predecessorsOf($rfq);

    expect($predecessors)->toHaveCount(1)
        ->and($predecessors->first()->getKey())->toBe($pr->getKey());
});

/*
|--------------------------------------------------------------------------
| Control bypasses — found by security review of P1-03
|--------------------------------------------------------------------------
|
| Every test below demonstrates a way to reach a forbidden outcome without ever
| calling the method that forbids it. That is the failure mode PLAN.md §5 warns
| about in its own words: a control enforced in one code path is not a control,
| because a queued job, an importer or a second service walks past it.
|
| The first is the worst. The service comment claimed sole source "is declared
| when the RFQ is opened" — an invariant the code asserted in prose and did not
| enforce anywhere.
|
*/

it('refuses to flip an open RFQ to sole source', function () {
    // Flipping the flag on a draft dodges the three-quote minimum outright:
    // open normally, set sole_source, issue to one vendor. The control PLAN.md
    // §5 names is gone, and nothing in the document says it ever applied.
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    expect(fn () => $rfq->update(['sole_source' => true]))
        ->toThrow(DomainException::class);

    expect($rfq->fresh()->sole_source)->toBeFalse();
});

it('refuses to change an RFQ status outside the service', function () {
    // Setting status directly skips every check issue() performs, including the
    // recipient count.
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    expect(fn () => $rfq->update(['status' => RfqStatus::Issued]))
        ->toThrow(DomainException::class);
});

it('refuses to invite a vendor after the RFQ has been issued', function () {
    // The recipient list is what the three-quote minimum was measured against.
    // Changing it afterwards makes the check describe a list that no longer
    // exists.
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    foreach (['VEN-A', 'VEN-B', 'VEN-C'] as $code) {
        rfqs()->invite($rfq, accreditedVendor($code));
    }
    rfqs()->issue($rfq);

    expect(fn () => rfqs()->invite($rfq->fresh(), accreditedVendor('VEN-LATE')))
        ->toThrow(DomainException::class);
});

it('refuses to issue an RFQ that has already been issued', function () {
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    foreach (['VEN-A', 'VEN-B', 'VEN-C'] as $code) {
        rfqs()->invite($rfq, accreditedVendor($code));
    }
    rfqs()->issue($rfq);

    expect(fn () => rfqs()->issue($rfq->fresh()))->toThrow(DomainException::class);
});

it('refuses to issue a cancelled RFQ', function () {
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));

    foreach (['VEN-A', 'VEN-B', 'VEN-C'] as $code) {
        rfqs()->invite($rfq, accreditedVendor($code));
    }

    rfqs()->cancel($rfq, 'Requirement withdrawn.');

    expect(fn () => rfqs()->issue($rfq->fresh()))->toThrow(DomainException::class);

    // The reason is kept. A withdrawn solicitation that does not say why is
    // the one somebody will ask about later.
    expect($rfq->fresh()->cancellation_reason)->toBe('Requirement withdrawn.');
});

it('refuses an ineligible vendor added straight through the relation', function () {
    // Gate parity. The eligibility check lives in invite(); the relation is
    // reachable from anywhere, so the same refusal has to hold there too.
    $rfq = rfqs()->open(approvedRequisition(), Carbon::parse('2026-05-20'));
    $expired = Vendor::factory()->create(['code' => 'VEN-EXPIRED']);
    app(VendorService::class)->accredit($expired, Carbon::parse('2024-01-01'));

    expect(fn () => $rfq->recipients()->create(['vendor_id' => $expired->getKey()]))
        ->toThrow(VendorNotEligibleException::class);

    expect($rfq->recipients()->count())->toBe(0);
});
