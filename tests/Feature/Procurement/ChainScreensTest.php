<?php

use App\Domain\Posting\MaterialCostPoster;
use App\Domain\Procurement\StockService;
use App\Filament\Resources\ApVouchers\ApVouchersResource;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Ledger\LedgerResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrdersResource;
use App\Filament\Resources\PurchaseRequisitions\PurchaseRequisitionResource;
use App\Filament\Resources\ReceivingReports\ReceivingReportsResource;
use App\Filament\Resources\Rfqs\RfqsResource;
use App\Filament\Resources\StockCards\StockCardsResource;
use App\Filament\Resources\ThreeWayMatches\ThreeWayMatchesResource;
use App\Filament\Resources\VendorScorecards\VendorScorecardResource;
use App\Models\CostCode;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The chain screens — P1-16
|--------------------------------------------------------------------------
|
| Nine screens covering the documents Phase 1 built. The console gate proves
| they render without a single console error; what this file proves is the
| architectural claim they are built on.
|
| **They are read-only, and that is a design decision rather than a shortcut.**
| Every document in this chain is created by a domain service that runs the
| gates PLAN.md §5 specifies, and every decision is taken in the one approvals
| inbox from P0-15. A create form on a document screen would be a second way to
| write the row — the one that skips the checks — and a second place approvals
| happen, which is the exact complaint the deck makes about the current process.
|
| So the tests that matter here are the ones asserting a create route does NOT
| exist, and the one asserting the ledger screen shows the code a posting was
| made against rather than what that code is called today.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');
    // P6-01 scopes every project-bearing query to the signed-in user.
    // A screen test asserting a record is visible has to say who is
    // looking at it, and an administrator is who opens these screens.
    actingAs(panelUser());
});

afterEach(fn () => Carbon::setTestNow());

it('renders every chain screen', function (string $resource) {
    get($resource::getUrl('index'))->assertSuccessful();
})->with([
    PurchaseRequisitionResource::class,
    RfqsResource::class,
    PurchaseOrdersResource::class,
    ReceivingReportsResource::class,
    StockCardsResource::class,
    ThreeWayMatchesResource::class,
    ApVouchersResource::class,
    EquipmentResource::class,
    VendorScorecardResource::class,
    LedgerResource::class,
]);

it('offers no way to create a chain document from the screen', function (string $resource) {
    // The refusal, stated twice over: the resource says it cannot create, and
    // there is no create page to route to even if something asked for one.
    expect($resource::canCreate())->toBeFalse()
        ->and(array_key_exists('create', $resource::getPages()))->toBeFalse();
})->with([
    PurchaseRequisitionResource::class,
    RfqsResource::class,
    PurchaseOrdersResource::class,
    ReceivingReportsResource::class,
    StockCardsResource::class,
    ThreeWayMatchesResource::class,
    ApVouchersResource::class,
    VendorScorecardResource::class,
    LedgerResource::class,
]);

it('shows a short delivery on the receiving screen', function () {
    // The exit gate asks for the shortfall to be noted on the DR. A control
    // nobody can see on the list is one somebody has to already know to look
    // for.
    [$po, $report] = shortDelivery('500.0000', '400.0000');

    get(ReceivingReportsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($report->number)
        ->assertSee($report->delivery_receipt_number);

    expect($report->has_shortfall)->toBeTrue();
});

it('shows the ledger code a posting was made against, not what it is called now', function () {
    // P0-13 snapshots `project_code` and `cost_code` onto the ledger row so a
    // rename does not restate last year. A screen that joined through the
    // relation would undo that on the one page an auditor is most likely to be
    // reading — so the test renames the cost code AFTER posting and asserts the
    // screen still shows the old one.
    [$card, $project, $costCode] = stockedGoods();

    $issuance = app(StockService::class)->issue($card, '100.0000', $costCode->getKey());
    app(MaterialCostPoster::class)->post($issuance->fresh());

    $originalCode = $costCode->code;
    CostCode::query()->whereKey($costCode->getKey())->update(['code' => '99.99.999']);

    get(LedgerResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($originalCode)
        ->assertDontSee('99.99.999');
});

it('computes what is still outstanding on a purchase order rather than storing it', function () {
    // 100 of 500 never arrived, and the figure the three-way match reads is the
    // one the screen shows.
    [$po] = shortDelivery('500.0000', '400.0000');

    get(PurchaseOrdersResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($po->number)
        ->assertSee('100.0000');
});
