<?php

use App\Domain\Gates\GateFailedException;
use App\Domain\Mobilization\ChecklistItem;
use App\Domain\Mobilization\MobilizationService;
use App\Domain\Mobilization\MobilizationStatus;
use App\Domain\Mobilization\PermitService;
use App\Domain\Mobilization\PermitType;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Models\Permit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Mobilization and permits — P2-01, closing F2
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md: "Step 5 has no home in PLAN.md. The gate (no mobilization
| without a countersigned PO) is listed in §5, but there is no `mobilizations`
| table, no checklist, and no `permits` table — despite 'site setup and permits
| secured' being explicit."
|
| **The whole finding turns on one word: COUNTERSIGNED.** Slide 3's obligation
| says it plainly — a PO can be approved internally and not yet countersigned by
| the vendor, and the mobilization gate tests the second, not the first. Those
| are two different facts about the same document: one says the company decided
| to buy, the other says the supplier agreed to sell. Mobilizing on the first is
| how a crew, a compound and a generator arrive on site against an order the
| vendor never accepted.
|
| The second half is "site setup and permits secured". A checklist item anybody
| can tick is a checklist that records intent rather than fact, so the permit
| item is backed by an actual permit record with an actual expiry.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function mobilizations(): MobilizationService
{
    return app(MobilizationService::class);
}

function permits(): PermitService
{
    return app(PermitService::class);
}

it('refuses to mobilize against a purchase order that is only approved', function () {
    // THE FINDING. The order is approved — internally decided — and the vendor
    // has not countersigned it. A gate that tested "approved" would pass this,
    // and the crew would arrive against an order nobody on the other side has
    // agreed to.
    [$po, $project] = orderAwaitingCountersignature();

    expect($po->status)->toBe(PurchaseOrderStatus::Approved);

    expect(fn () => mobilizations()->mobilize($po, Carbon::parse('2026-05-18')))
        ->toThrow(GateFailedException::class);
});

it('mobilizes once the vendor has countersigned', function () {
    [$po] = orderAwaitingCountersignature();

    purchaseOrders()->countersign($po, Carbon::parse('2026-05-16'), 'R. Villanueva, Northgate Cement');

    $mobilization = mobilizations()->mobilize($po->fresh(), Carbon::parse('2026-05-18'));

    expect($mobilization->number)->toStartWith('MOB-2026-')
        ->and($mobilization->status)->toBe(MobilizationStatus::InProgress);
});

it('refuses to countersign an order that was never approved', function () {
    // Countersignature is the vendor accepting what the company decided. There
    // is nothing to accept until the company has decided.
    $c = canvassed();
    $po = purchaseOrders()->raise($c['tabulation'], [
        ['description' => 'Site offices', 'quantity' => '1.0000', 'unit_price' => '124500.0000', 'unit' => 'lot'],
    ]);

    expect(fn () => purchaseOrders()->countersign($po->fresh(), Carbon::parse('2026-05-16'), 'R. Villanueva'))
        ->toThrow(DomainException::class);
});

it('records who countersigned and when, not merely that somebody did', function () {
    // The gate reads a state; a dispute reads the name and the date. Storing
    // only the status would make "who accepted this order" unanswerable.
    [$po] = orderAwaitingCountersignature();

    purchaseOrders()->countersign($po, Carbon::parse('2026-05-16'), 'R. Villanueva, Northgate Cement');

    $fresh = $po->fresh();

    expect($fresh->status)->toBe(PurchaseOrderStatus::Countersigned)
        ->and($fresh->countersigned_by)->toBe('R. Villanueva, Northgate Cement')
        ->and($fresh->countersigned_at->toDateString())->toBe('2026-05-16');
});

it('refuses a second mobilization against one purchase order', function () {
    // Two mobilizations on one order is the same crew mobilized twice on paper,
    // and both would be chargeable.
    [$po] = orderAwaitingCountersignature();
    purchaseOrders()->countersign($po, Carbon::parse('2026-05-16'), 'R. Villanueva');

    mobilizations()->mobilize($po->fresh(), Carbon::parse('2026-05-18'));

    expect(fn () => mobilizations()->mobilize($po->fresh(), Carbon::parse('2026-05-19')))
        ->toThrow(QueryException::class);
});

it('opens the checklist with every required item outstanding', function () {
    // The checklist is created WITH the mobilization rather than assembled by
    // hand afterwards. A checklist somebody builds is a checklist somebody can
    // build short.
    [$po] = orderAwaitingCountersignature();
    purchaseOrders()->countersign($po, Carbon::parse('2026-05-16'), 'R. Villanueva');

    $mobilization = mobilizations()->mobilize($po->fresh(), Carbon::parse('2026-05-18'));

    expect($mobilization->items()->count())->toBe(count(ChecklistItem::cases()))
        ->and($mobilization->items()->whereNotNull('completed_at')->count())->toBe(0);
});

it('refuses to complete a mobilization with required items outstanding', function () {
    [$po] = orderAwaitingCountersignature();
    purchaseOrders()->countersign($po, Carbon::parse('2026-05-16'), 'R. Villanueva');
    $mobilization = mobilizations()->mobilize($po->fresh(), Carbon::parse('2026-05-18'));

    expect(fn () => mobilizations()->complete($mobilization, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses to tick the permits item while the project holds no permit', function () {
    // "Site setup and permits secured" is a fact about the project, not a box
    // somebody's pen reached. A tickable-by-anyone checklist records intent.
    [$po, $project] = orderAwaitingCountersignature();
    purchaseOrders()->countersign($po, Carbon::parse('2026-05-16'), 'R. Villanueva');
    $mobilization = mobilizations()->mobilize($po->fresh(), Carbon::parse('2026-05-18'));

    expect(fn () => mobilizations()->completeItem($mobilization, ChecklistItem::PermitsSecured, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses to tick the permits item on an expired permit', function () {
    // A permit is a second expiry clock, like an accreditation and like a bond.
    // Expired is the state that passes an eyeball check.
    [$po, $project] = orderAwaitingCountersignature();
    purchaseOrders()->countersign($po, Carbon::parse('2026-05-16'), 'R. Villanueva');
    $mobilization = mobilizations()->mobilize($po->fresh(), Carbon::parse('2026-05-18'));

    permits()->register(
        $project,
        PermitType::BuildingPermit,
        'BP-2025-3391',
        'Quezon City Building Official',
        Carbon::parse('2025-01-10'),
        Carbon::parse('2026-01-09'),
    );

    expect(fn () => mobilizations()->completeItem($mobilization, ChecklistItem::PermitsSecured, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('completes a mobilization once every required item is done', function () {
    [$po, $project] = orderAwaitingCountersignature();
    purchaseOrders()->countersign($po, Carbon::parse('2026-05-16'), 'R. Villanueva');
    $mobilization = mobilizations()->mobilize($po->fresh(), Carbon::parse('2026-05-18'));

    permits()->register(
        $project,
        PermitType::BuildingPermit,
        'BP-2026-0114',
        'Quezon City Building Official',
        Carbon::parse('2026-02-01'),
        Carbon::parse('2027-01-31'),
    );

    $user = User::factory()->create();

    foreach (ChecklistItem::cases() as $item) {
        mobilizations()->completeItem($mobilization->fresh(), $item, $user);
    }

    $completed = mobilizations()->complete($mobilization->fresh(), $user);

    expect($completed->status)->toBe(MobilizationStatus::Completed)
        ->and($completed->completed_at)->not->toBeNull();
});

it('treats a permit as valid up to and including its expiry date', function () {
    // The boundary. Somebody will stand on the last day of a permit, and being
    // wrong in either direction is a site either idled or working uncovered.
    [$po, $project] = orderAwaitingCountersignature();

    $permit = permits()->register(
        $project,
        PermitType::BuildingPermit,
        'BP-2026-0114',
        'Quezon City Building Official',
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-05-14'),
    );

    expect(permits()->isValid($permit, Carbon::parse('2026-05-14')))->toBeTrue()
        ->and(permits()->isValid($permit, Carbon::parse('2026-05-15')))->toBeFalse();
});

it('refuses a permit that expires before it was issued', function () {
    [$po, $project] = orderAwaitingCountersignature();

    expect(fn () => permits()->register(
        $project,
        PermitType::EnvironmentalClearance,
        'ECC-2026-8',
        'DENR',
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-04-01'),
    ))->toThrow(DomainException::class);
});

it('keeps a renewed permit alongside the one it replaced', function () {
    // The question an auditor asks is "was the site permitted on the day of the
    // incident", and overwriting keeps only the answer for today.
    [$po, $project] = orderAwaitingCountersignature();

    permits()->register($project, PermitType::BuildingPermit, 'BP-2025-3391', 'QC', Carbon::parse('2025-01-10'), Carbon::parse('2026-01-09'));
    permits()->register($project, PermitType::BuildingPermit, 'BP-2026-0114', 'QC', Carbon::parse('2026-01-10'), Carbon::parse('2027-01-09'));

    expect(Permit::query()->where('project_id', $project->getKey())->count())->toBe(2)
        ->and(permits()->validFor($project, PermitType::BuildingPermit)?->number)->toBe('BP-2026-0114');
});
