<?php

/*
|--------------------------------------------------------------------------
| Shared procurement fixtures
|--------------------------------------------------------------------------
|
| The procurement chain is deep — by the time a test needs a receiving report it
| needs an approved PR, an issued RFQ, three quotes, a tabulation and an
| approved PO behind it. Rebuilding that in every suite would be noise, and
| leaving the builders in whichever test file happened to define them first
| means a suite only passes when run alongside its neighbours.
|
| These deliberately go through the real services rather than inserting rows.
| A fixture that bypasses the gates would let a test pass against a chain the
| application itself would have refused to create.
|
*/

use App\Domain\Approvals\ApprovalDecisions;
use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Procurement\InspectionService;
use App\Domain\Procurement\PayablesService;
use App\Domain\Procurement\PurchaseOrderService;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\QuoteService;
use App\Domain\Procurement\ReceivingService;
use App\Domain\Procurement\RfqService;
use App\Domain\Procurement\SoleSourceReason;
use App\Domain\Procurement\SoleSourceService;
use App\Domain\Procurement\StockService;
use App\Domain\Procurement\SubcontractService;
use App\Domain\Procurement\TabulationService;
use App\Domain\Procurement\ThreeWayMatchService;
use App\Domain\Requisitions\RequisitionStatus;
use App\Domain\Vendors\BondType;
use App\Domain\Vendors\VendorService;
use App\Models\ApVoucher;
use App\Models\BidTabulation;
use App\Models\CostCode;
use App\Models\CutoffCalendar;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\ReceivingReport;
use App\Models\Rfq;
use App\Models\SoleSourceJustification;
use App\Models\StockCard;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * The four-tier authority matrix used across the procurement suites.
 *
 * PLACEHOLDER: Part D item 1 — these mirror ApprovalMatrixSeeder and are the
 * build's own bands, not the client's. P1-17 re-tests when the real ones land.
 */
function defineProcurementTiers(string $documentType = 'purchase_order'): void
{
    $router = app(ApprovalRouter::class);

    $router->defineTier($documentType, 1, '0.0000', '50000.0000', ['project-manager'], []);
    $router->defineTier($documentType, 2, '50000.0001', '500000.0000', ['project-manager', 'procurement-head'], []);
    $router->defineTier($documentType, 3, '500000.0001', '5000000.0000', ['procurement-head', 'finance-manager'], []);
    $router->defineTier($documentType, 4, '5000000.0001', null, ['finance-manager', 'managing-director'], []);

    foreach (['project-manager', 'procurement-head', 'finance-manager', 'managing-director'] as $role) {
        Role::findOrCreate($role);
    }
}

function accreditedVendorNamed(string $code, ?Carbon $from = null): Vendor
{
    $vendor = Vendor::factory()->create(['code' => $code]);
    app(VendorService::class)->accredit($vendor, $from ?? Carbon::parse('2026-05-01'));

    return $vendor->fresh();
}

/**
 * A canvassed RFQ: approved PR, three quotes, tabulated.
 *
 * @return array{pr: PurchaseRequisition, rfq: Rfq, tabulation: BidTabulation, winner: Vendor}
 */
function canvassed(string $winningAmount = '124500.0000', ?Vendor $winner = null): array
{
    $pr = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Approved]);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'));

    $vendors = [];

    foreach (['VEN-A', 'VEN-B', 'VEN-C'] as $index => $code) {
        // The winner is the first invitee because it quotes the lowest price
        // below. Passing one in is how a suite builds a SECOND order for a
        // vendor it already has — which is what the quarterly scorecard rules
        // are counted over.
        $vendor = $index === 0 && $winner !== null
            ? $winner
            : accreditedVendorNamed($code.'-'.uniqid());

        app(RfqService::class)->invite($rfq, $vendor);
        $vendors[] = $vendor;
    }

    app(RfqService::class)->issue($rfq);
    $rfq = $rfq->fresh();

    $prices = [$winningAmount, '130000.0000', '131750.0000'];

    foreach ($vendors as $index => $vendor) {
        app(QuoteService::class)->record($rfq, $vendor, [
            ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => $prices[$index]],
        ]);
    }

    $tabulation = app(TabulationService::class)->tabulate($rfq->fresh());

    return ['pr' => $pr, 'rfq' => $rfq->fresh(), 'tabulation' => $tabulation, 'winner' => $vendors[0]];
}

function purchaseOrders(): PurchaseOrderService
{
    return app(PurchaseOrderService::class);
}

/**
 * A sole-source canvass with an unsigned justification on file.
 *
 * @return array{0: BidTabulation, 1: SoleSourceJustification}
 */
function justifiedSoleSource(): array
{
    $pr = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Approved]);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'), soleSource: true);

    $vendor = accreditedVendorNamed('VEN-ONLY-'.uniqid());
    app(RfqService::class)->invite($rfq, $vendor);
    app(RfqService::class)->issue($rfq);

    app(QuoteService::class)->record($rfq->fresh(), $vendor, [
        ['description' => 'Proprietary valve', 'quantity' => '1.0000', 'unit_price' => '85000.0000'],
    ]);

    $tabulation = app(TabulationService::class)->tabulate($rfq->fresh());

    $justification = app(SoleSourceService::class)->justify(
        $rfq->fresh(),
        $vendor,
        SoleSourceReason::ProprietaryItem,
        'Only authorised distributor of the specified valve.',
        '2000000.0000',
    );

    return [$tabulation, $justification];
}

/**
 * A receiving report for 500 delivered units.
 */
function receivedGoods(string $quantity = '500.0000'): array
{
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Portland cement', 'quantity' => $quantity, 'unit_price' => '249.0000', 'unit' => 'bags'],
    ]);
    PurchaseOrder::mutate(fn () => $po->update(['status' => PurchaseOrderStatus::Approved]));
    $po = $po->fresh();

    $report = app(ReceivingService::class)->receive($po, 'DR-'.uniqid(), [
        ['purchase_order_line_id' => $po->lines()->sole()->getKey(), 'quantity_received' => $quantity],
    ], User::factory()->create());

    return [$po, $report];
}

/**
 * A short delivery: `$ordered` on the purchase order, `$delivered` actually
 * received, and inspection accepting all of what turned up.
 *
 * The point of this shape is that the PO and the invoice can agree with each
 * other and still both be wrong. Only the receiving report knows how much
 * arrived, which is the whole argument for a three-way match over a two-way one.
 *
 * @return array{0: PurchaseOrder, 1: ReceivingReport}
 */
function shortDelivery(string $ordered = '500.0000', string $delivered = '400.0000'): array
{
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Portland cement', 'quantity' => $ordered, 'unit_price' => '249.0000', 'unit' => 'bags'],
    ]);
    PurchaseOrder::mutate(fn () => $po->update(['status' => PurchaseOrderStatus::Approved]));
    $po = $po->fresh();

    $report = app(ReceivingService::class)->receive($po, 'DR-'.uniqid(), [
        ['purchase_order_line_id' => $po->lines()->sole()->getKey(), 'quantity_received' => $delivered],
    ], User::factory()->create());

    app(InspectionService::class)->inspect($report, [
        [
            'receiving_report_line_id' => $report->lines()->sole()->getKey(),
            'quantity_accepted' => $delivered,
            'quantity_rejected' => '0.0000',
        ],
    ], User::factory()->create());

    return [$po->fresh(), $report->fresh()];
}

/**
 * Inspected, accepted goods ready to enter stock or to be paid for.
 *
 * @return array{0: PurchaseOrder, 1: ReceivingReport}
 */
function acceptedGoods(string $quantity = '500.0000'): array
{
    [$po, $report] = receivedGoods($quantity);

    app(InspectionService::class)->inspect($report, [
        [
            'receiving_report_line_id' => $report->lines()->sole()->getKey(),
            'quantity_accepted' => $quantity,
            'quantity_rejected' => '0.0000',
        ],
    ], User::factory()->create());

    return [$po, $report->fresh()];
}

/**
 * A second (or third) delivered, inspected order for a vendor that already has
 * one. The scorecard's suspension rules are counts within a quarter, so a suite
 * proving them needs more than one order against the same supplier.
 *
 * @return array{0: PurchaseOrder, 1: ReceivingReport}
 */
function deliveredOrderFor(Vendor $vendor, string $deliveredOn = '2026-05-12', string $rejected = '0.0000'): array
{
    $c = canvassed(winner: $vendor);

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Portland cement', 'quantity' => '500.0000', 'unit_price' => '249.0000', 'unit' => 'bags'],
    ]);

    PurchaseOrder::mutate(fn () => $po->update([
        'status' => PurchaseOrderStatus::Approved,
        'delivery_date' => '2026-05-15',
    ]));

    $po = $po->fresh();

    $report = app(ReceivingService::class)->receive($po, 'DR-'.uniqid(), [
        ['purchase_order_line_id' => $po->lines()->sole()->getKey(), 'quantity_received' => '500.0000'],
    ], User::factory()->create());

    app(InspectionService::class)->inspect($report, [
        [
            'receiving_report_line_id' => $report->lines()->sole()->getKey(),
            'quantity_accepted' => bcsub('500.0000', $rejected, 4),
            'quantity_rejected' => $rejected,
            'rejection_reason' => bccomp($rejected, '0', 4) > 0 ? 'Hardened in transit.' : null,
        ],
    ], User::factory()->create());

    $report->forceFill(['received_at' => Carbon::parse($deliveredOn)])->saveQuietly();

    return [$po->fresh(), $report->fresh()];
}

/**
 * Accepted goods in stock, with an open billing period to post into.
 *
 * @return array{0: StockCard, 1: Project, 2: CostCode}
 */
function stockedGoods(string $cutoff = '2026-06-10 17:00:00'): array
{
    [$po, $report] = acceptedGoods('500.0000');

    $project = $po->project()->sole();

    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Billing,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'cutoff_at' => $cutoff,
    ]);

    $card = app(StockService::class)->receive($report->fresh(), User::factory()->create());

    $costCode = CostCode::factory()->create(['organization_id' => $project->organization_id]);

    return [$card->refresh(), $project, $costCode];
}

/**
 * An approved order and its project — approved, and deliberately NOT
 * countersigned.
 *
 * Named for what it IS rather than `approvedOrder()`, which the receiving suite
 * already defines with a different shape — and which is the distinction the
 * whole F2 gate turns on.
 *
 * @return array{0: PurchaseOrder, 1: Project}
 */
function orderAwaitingCountersignature(): array
{
    $c = canvassed();

    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Site offices and temporary works', 'quantity' => '1.0000', 'unit_price' => '124500.0000', 'unit' => 'lot'],
    ]);

    PurchaseOrder::mutate(fn () => $po->update(['status' => PurchaseOrderStatus::Approved]));

    return [$po->fresh(), $po->project()->sole()];
}

function subcontracts(): SubcontractService
{
    return app(SubcontractService::class);
}

/**
 * An accredited subcontractor with a performance bond that outlasts the works.
 *
 * Shared from the tenth extraction onward rather than after the fatal: Phase 5
 * back-charges against subcontracts, so the close-out suites want a subcontractor
 * the award service will actually accept.
 */
function bondedSubcon(string $code = 'SUB-A'): Vendor
{
    $vendor = Vendor::factory()->create(['code' => $code]);
    $vendors = app(VendorService::class);

    $vendors->accredit($vendor, Carbon::parse('2026-05-01'));
    $vendors->registerBond(
        $vendor, BondType::Performance, '500000.0000',
        Carbon::parse('2026-01-01'), Carbon::parse('2027-01-01'), 'BOND-'.$code,
    );

    return $vendor->fresh();
}

function matches(): ThreeWayMatchService
{
    return app(ThreeWayMatchService::class);
}

function payables(): PayablesService
{
    return app(PayablesService::class);
}

/**
 * A raised AP voucher for an accepted delivery.
 *
 * The amount chooses the approval tier, so a suite that wants a single-signature
 * voucher passes something inside tier 1.
 */
function raisedVoucher(string $invoiceAmount = '124500.0000'): ApVoucher
{
    defineProcurementTiers('ap_voucher');

    [$po, $report] = acceptedGoods();
    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-'.uniqid(), $invoiceAmount);

    return payables()->raise($match, 'goods');
}

/**
 * A voucher through every signature of its tier, ready to pay.
 */
function approvedVoucher(string $invoiceAmount = '40000.0000'): ApVoucher
{
    $voucher = raisedVoucher($invoiceAmount);
    payables()->submit($voucher);

    $signer = userWithRole('project-manager');

    foreach ($voucher->approvals()->orderBy('step')->get() as $step) {
        app(ApprovalDecisions::class)->approve($step, $signer, null);
    }

    return $voucher->fresh();
}
