<?php

use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Approvals\ApproverLacksAuthorityException;
use App\Domain\Documents\DocumentLinker;
use App\Domain\Procurement\JustificationRequiredException;
use App\Domain\Procurement\RfqService;
use App\Domain\Procurement\SoleSourceReason;
use App\Domain\Procurement\SoleSourceService;
use App\Domain\Requisitions\RequisitionStatus;
use App\Domain\Vendors\VendorService;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Sole-source justification — P1-05
|--------------------------------------------------------------------------
|
| PLAN.md §5: "Three quotes minimum before award; sole source needs written
| justification approved one level up."
|
| P1-03 and P1-04 let sole source skip the three-quote minimum. This is the
| price of that exemption, and without it the exemption is simply a way around
| the control: declare sole source, invite one vendor, award. The justification
| is what makes it a decision somebody signed for.
|
| "One level up" is the part worth getting right. The escalation is computed
| from the AMOUNT — a ₱2m sole-source purchase escalates from tier 3 to tier 4,
| not to whatever tier the person raising it happens to sit in. ApprovalRouter
| has known how to do this since P0-12; nothing had used it in anger until now.
|
| PLACEHOLDER: Part D item 1. The tier bands are still the build's own, so the
| escalation is proven in shape and provisional in threshold. P1-17 re-tests it.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');

    $router = app(ApprovalRouter::class);
    $router->defineTier('purchase_order', 1, '0.0000', '50000.0000', ['project-manager'], []);
    $router->defineTier('purchase_order', 2, '50000.0001', '500000.0000', ['project-manager', 'procurement-head'], []);
    $router->defineTier('purchase_order', 3, '500000.0001', '5000000.0000', ['procurement-head', 'finance-manager'], []);
    $router->defineTier('purchase_order', 4, '5000000.0001', null, ['finance-manager', 'managing-director'], []);

    foreach (['project-manager', 'procurement-head', 'finance-manager', 'managing-director'] as $role) {
        Role::findOrCreate($role);
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

function soleSource(): SoleSourceService
{
    return app(SoleSourceService::class);
}

function soleSourceRfq(): array
{
    $pr = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Approved]);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'), soleSource: true);

    $vendor = Vendor::factory()->create(['code' => 'VEN-ONLY']);
    app(VendorService::class)->accredit($vendor, Carbon::parse('2026-05-01'));
    app(RfqService::class)->invite($rfq, $vendor->fresh());
    app(RfqService::class)->issue($rfq);

    return [$rfq->fresh(), $vendor->fresh()];
}

it('records a written justification against a sole-source RFQ', function () {
    [$rfq, $vendor] = soleSourceRfq();

    $justification = soleSource()->justify(
        $rfq,
        $vendor,
        SoleSourceReason::ProprietaryItem,
        'Only authorised distributor of the specified valve in the country.',
        '2000000.0000',
    );

    expect($justification->reason)->toBe(SoleSourceReason::ProprietaryItem)
        ->and($justification->narrative)->toContain('Only authorised distributor');
});

it('refuses a justification with no narrative', function () {
    // "Sole source" as a checkbox is not a justification. The narrative is the
    // whole artefact — a reason code alone records that somebody clicked.
    [$rfq, $vendor] = soleSourceRfq();

    expect(fn () => soleSource()->justify(
        $rfq,
        $vendor,
        SoleSourceReason::ProprietaryItem,
        '   ',
        '2000000.0000',
    ))->toThrow(JustificationRequiredException::class);
});

it('refuses a justification against an RFQ that is not sole source', function () {
    $pr = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Approved]);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'));

    $vendor = Vendor::factory()->create();
    app(VendorService::class)->accredit($vendor, Carbon::parse('2026-05-01'));

    expect(fn () => soleSource()->justify(
        $rfq,
        $vendor->fresh(),
        SoleSourceReason::ProprietaryItem,
        'Not applicable.',
        '2000000.0000',
    ))->toThrow(DomainException::class);
});

it('routes the justification one tier above the amount', function () {
    // THE CONTROL. ₱2m sits in tier 3; the sole-source justification is
    // approved at tier 4.
    [$rfq, $vendor] = soleSourceRfq();

    $justification = soleSource()->justify(
        $rfq, $vendor, SoleSourceReason::ProprietaryItem,
        'Only authorised distributor.', '2000000.0000',
    );

    $steps = $justification->approvals()->orderBy('step')->get();

    expect($justification->tier)->toBe(4)
        ->and($steps->pluck('approver_role')->all())->toBe(['finance-manager', 'managing-director']);
});

it('escalates from the amount, not from the raiser', function () {
    // A ₱25,000 sole source is tier 1 by amount and escalates to tier 2. If the
    // escalation were relative to whoever raised it, the same purchase would
    // need different approval depending on who typed it in.
    [$rfq, $vendor] = soleSourceRfq();

    $justification = soleSource()->justify(
        $rfq, $vendor, SoleSourceReason::EmergencyRequirement,
        'Pump failure halting concrete pour.', '25000.0000',
    );

    expect($justification->tier)->toBe(2);
});

it('keeps a top-tier sole source at the top tier', function () {
    // There is no level above the highest. Stated as a decision rather than
    // left to an off-by-one.
    [$rfq, $vendor] = soleSourceRfq();

    $justification = soleSource()->justify(
        $rfq, $vendor, SoleSourceReason::ProprietaryItem,
        'Sole licensed supplier.', '25000000.0000',
    );

    expect($justification->tier)->toBe(4);
});

it('is not approved until every approver at the escalated tier has signed', function () {
    [$rfq, $vendor] = soleSourceRfq();

    $justification = soleSource()->justify(
        $rfq, $vendor, SoleSourceReason::ProprietaryItem,
        'Only authorised distributor.', '2000000.0000',
    );

    $finance = User::factory()->create();
    $finance->assignRole('finance-manager');

    $steps = $justification->approvals()->orderBy('step')->get();
    soleSource()->approve($justification, $steps[0], $finance);

    expect($justification->fresh()->isApproved())->toBeFalse();
});

it('is approved once the escalated tier has fully signed', function () {
    [$rfq, $vendor] = soleSourceRfq();

    $justification = soleSource()->justify(
        $rfq, $vendor, SoleSourceReason::ProprietaryItem,
        'Only authorised distributor.', '2000000.0000',
    );

    $finance = User::factory()->create();
    $finance->assignRole('finance-manager');
    $director = User::factory()->create();
    $director->assignRole('managing-director');

    $steps = $justification->approvals()->orderBy('step')->get();
    soleSource()->approve($justification, $steps[0], $finance);
    soleSource()->approve($justification, $steps[1], $director, 'Agreed, no alternative supplier.');

    expect($justification->fresh()->isApproved())->toBeTrue()
        ->and($steps[1]->fresh()->decision)->toBe(ApprovalDecision::Approved);
});

it('refuses a signature from someone outside the escalated tier', function () {
    // The point of escalating. If the tier below could sign its own exemption,
    // "approved one level up" would describe nothing.
    [$rfq, $vendor] = soleSourceRfq();

    $justification = soleSource()->justify(
        $rfq, $vendor, SoleSourceReason::ProprietaryItem,
        'Only authorised distributor.', '2000000.0000',
    );

    $head = User::factory()->create();
    $head->assignRole('procurement-head');

    $step = $justification->approvals()->orderBy('step')->first();

    expect(fn () => soleSource()->approve($justification, $step, $head))
        ->toThrow(ApproverLacksAuthorityException::class);
});

it('refuses a second justification for one RFQ', function () {
    [$rfq, $vendor] = soleSourceRfq();

    soleSource()->justify($rfq, $vendor, SoleSourceReason::ProprietaryItem, 'First.', '2000000.0000');

    expect(fn () => soleSource()->justify(
        $rfq, $vendor, SoleSourceReason::EmergencyRequirement, 'Second.', '2000000.0000',
    ))->toThrow(QueryException::class);
});

it('refuses a tier or amount changed by a direct update', function () {
    [$rfq, $vendor] = soleSourceRfq();

    $justification = soleSource()->justify(
        $rfq, $vendor, SoleSourceReason::ProprietaryItem,
        'Only authorised distributor.', '2000000.0000',
    );

    expect(fn () => $justification->update(['tier' => 1]))->toThrow(DomainException::class);
});

it('links the justification to its RFQ on the handoff spine', function () {
    [$rfq, $vendor] = soleSourceRfq();

    $justification = soleSource()->justify(
        $rfq, $vendor, SoleSourceReason::ProprietaryItem,
        'Only authorised distributor.', '2000000.0000',
    );

    $predecessors = app(DocumentLinker::class)->predecessorsOf($justification);

    expect($predecessors)->toHaveCount(1)
        ->and($predecessors->first()->getKey())->toBe($rfq->getKey());
});
