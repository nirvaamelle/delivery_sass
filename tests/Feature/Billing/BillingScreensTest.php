<?php

use App\Domain\Mobilization\MobilizationService;
use App\Domain\Mobilization\PermitService;
use App\Domain\Mobilization\PermitType;
use App\Filament\Resources\Accomplishments\AccomplishmentsResource;
use App\Filament\Resources\ArEscalations\ArEscalationsResource;
use App\Filament\Resources\Billings\BillingsResource;
use App\Filament\Resources\Mobilizations\MobilizationsResource;
use App\Filament\Resources\Permits\PermitsResource;
use App\Filament\Resources\SalesInvoices\SalesInvoicesResource;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The Phase 2 screens — P2-10
|--------------------------------------------------------------------------
|
| Six screens covering the acquisition-to-cash chain. The console gate proves
| they render clean; this file proves the two claims they are built on.
|
| **Read-only, as every document screen in this build is.** A billing is created
| by a service that runs three gates — the milestone's document set, the verified
| accomplishment, and the lines the client already deducted — and a form here
| would be the one path that skips all three.
|
| **And the returned branch is visible.** PHASE-PLAN.md calls it a first-class
| path rather than an error case; a screen that showed only approved billings
| would make it an error case again in the only place anybody looks.
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

it('renders every Phase 2 screen', function (string $resource) {
    get($resource::getUrl('index'))->assertSuccessful();
})->with([
    AccomplishmentsResource::class,
    BillingsResource::class,
    SalesInvoicesResource::class,
    ArEscalationsResource::class,
    MobilizationsResource::class,
    PermitsResource::class,
]);

it('offers no way to create a Phase 2 document from the screen', function (string $resource) {
    expect($resource::canCreate())->toBeFalse()
        ->and(array_key_exists('create', $resource::getPages()))->toBeFalse();
})->with([
    AccomplishmentsResource::class,
    BillingsResource::class,
    SalesInvoicesResource::class,
    ArEscalationsResource::class,
    MobilizationsResource::class,
    PermitsResource::class,
]);

it('shows a returned billing alongside an approved one', function () {
    // The branch, on screen. A list that showed only approved billings would
    // make the returned path invisible in the one place anybody looks for it.
    [$project, $milestone] = billableProgress('52.00');

    $returned = billings()->submit($milestone, [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ]);

    billings()->returnForRemeasurement($returned, Carbon::parse('2026-05-20'), 'Not tied at survey date.', [
        ['line_key' => 'pier-7-rebar', 'reason' => 'Not tied at survey date.'],
    ]);

    get(BillingsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($returned->number)
        ->assertSee('Returned');
});

it('shows what is still outstanding on an invoice rather than a stored figure', function () {
    // Summed from the receipts, so this screen and the AR sweep cannot disagree
    // about whether a client owes money — the one disagreement nobody notices
    // until a payment is chased twice.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    collections()->collect($invoice, '1000000.0000', Carbon::parse('2026-06-01'), 'BDO 1', '2307-1');

    get(SalesInvoicesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($invoice->number);

    expect(collections()->outstandingFor($invoice->fresh()))->toBe('11804000.0000');
});

it('shows an expired permit as out of force without storing a flag', function () {
    // The vendor register's rule, applied to the third expiry clock: computed on
    // read, so the screen cannot drift from the check the mobilization gate runs.
    [$po, $project] = orderAwaitingCountersignature();

    $expired = app(PermitService::class)->register(
        $project,
        PermitType::BuildingPermit,
        'BP-2025-EXPIRED',
        'Quezon City Building Official',
        Carbon::parse('2025-01-10'),
        Carbon::parse('2026-01-09'),
    );

    get(PermitsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('BP-2025-EXPIRED');

    expect(app(PermitService::class)->isValid($expired))->toBeFalse();
});

it('shows the outstanding checklist items on a mobilization', function () {
    // Named, not counted. A count says there is work left; the names say what to
    // go and do.
    [$po] = orderAwaitingCountersignature();
    purchaseOrders()->countersign($po, Carbon::parse('2026-05-16'), 'R. Villanueva');
    $mobilization = app(MobilizationService::class)
        ->mobilize($po->fresh(), Carbon::parse('2026-05-18'));

    get(MobilizationsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($mobilization->number)
        ->assertSee('Permits secured');
});
