<?php

use App\Domain\Vendors\ScorecardService;
use App\Domain\Vendors\VendorStatus;
use App\Domain\Vendors\VendorSuspensionReason;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The warranty register — P5-03, closes F7
|--------------------------------------------------------------------------
|
| F7: "Warranty certificates are collected at turnover, and an unresolved
| warranty claim suspends a vendor (slide 5). So warranties need a register that
| links the certificate to the vendor and to any claim raised against it.
| PLAN.md has neither the warranty register nor the claim."
|
| Half of that arrived early. `warranty_claims` was built in P1-14 because F11's
| suspension trigger needed something to fire on, and it has been firing ever
| since against a vendor and a purchase order. What it has never had is the
| certificate — so a claim could name a vendor, but not the promise the vendor
| is being held to.
|
| This suite adds the other half and joins them. Three things are the point:
|
| **A certificate is a promise with an end date.** Coverage is a period, and the
| question a register exists to answer is whether the failure happened inside
| it. The operative date is the FAILURE, not the paperwork — a compressor that
| died a week before expiry is covered however long the claim took to be typed,
| and a register that checked the filing date would deny it.
|
| **The claim reaches the vendor through the same door as before.** Raising a
| claim from the register still suspends the vendor, because it is the same
| ScorecardService call F11 closed against. A second path that recorded a claim
| without suspending would quietly disarm the trigger for exactly the claims
| that have a certificate behind them — the well-documented ones.
|
| **A claim cannot name another vendor's certificate.** Enforced past the
| service by a composite foreign key, because a claim that points at the wrong
| promise suspends the wrong company.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| Registering the certificate
|--------------------------------------------------------------------------
*/

it('registers a warranty certificate against a vendor and a project', function () {
    [$project, $vendor] = warrantyParties();

    $warranty = warranties()->register(
        project: $project,
        vendor: $vendor,
        certificateReference: 'ACME-WC-8841',
        scope: 'Roof membrane, materials and workmanship',
        startsOn: Carbon::parse('2026-05-18'),
        endsOn: Carbon::parse('2027-05-18'),
        by: User::factory()->create(),
    );

    expect($warranty->number)->toStartWith('WTY-2026-')
        ->and($warranty->certificate_reference)->toBe('ACME-WC-8841')
        ->and((int) $warranty->vendor_id)->toBe($vendor->getKey())
        ->and((int) $warranty->project_id)->toBe($project->getKey())
        ->and($warranty->ends_on->toDateString())->toBe('2027-05-18')
        ->and($warranty->received_at)->not->toBeNull();
});

it('refuses a certificate with no reference of its own', function () {
    // A register entry with no certificate number is a note saying somebody
    // remembers a warranty existing. It cannot be produced at a claim.
    [$project, $vendor] = warrantyParties();

    expect(fn () => warranties()->register(
        $project, $vendor, '   ', 'Roof membrane',
        Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), User::factory()->create(),
    ))->toThrow(DomainException::class, 'certificate reference');
});

it('refuses a certificate that does not say what it covers', function () {
    // Scope is what a claim is argued against. "Warranty, 1 year" covers
    // whatever the vendor later says it covers.
    [$project, $vendor] = warrantyParties();

    expect(fn () => warranties()->register(
        $project, $vendor, 'ACME-WC-8841', '',
        Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), User::factory()->create(),
    ))->toThrow(DomainException::class, 'what it covers');
});

it('refuses coverage that ends before it starts', function () {
    [$project, $vendor] = warrantyParties();

    expect(fn () => warranties()->register(
        $project, $vendor, 'ACME-WC-8841', 'Roof membrane',
        Carbon::parse('2027-05-18'), Carbon::parse('2026-05-18'), User::factory()->create(),
    ))->toThrow(DomainException::class, 'ends before it starts');
});

it('refuses coverage that ends before it starts, at the database', function () {
    // An inverted period is in force on no date at all, so every claim under it
    // is denied and the register reads as if the certificate were never
    // collected. A control that lives only in a service is not a control.
    $warranty = registeredWarranty();

    expect(fn () => Warranty::query()->whereKey($warranty->getKey())->update([
        'ends_on' => '2026-05-17',
    ]))->toThrow(QueryException::class);
});

it('refuses the same certificate registered against one vendor twice', function () {
    // Two register rows for one certificate is two expiry dates for one
    // promise, and the turnover pack counts the certificate twice.
    [$project, $vendor] = warrantyParties();

    warranties()->register(
        $project, $vendor, 'ACME-WC-8841', 'Roof membrane',
        Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), User::factory()->create(),
    );

    expect(fn () => warranties()->register(
        $project, $vendor, 'ACME-WC-8841', 'Roof membrane, resubmitted',
        Carbon::parse('2026-05-18'), Carbon::parse('2028-05-18'), User::factory()->create(),
    ))->toThrow(DomainException::class, 'already registered');
});

it('lets two vendors hold certificates numbered the same', function () {
    // The reference is the VENDOR'S own numbering. Two suppliers both issuing
    // "0001" is ordinary, and a register unique on the reference alone would
    // refuse the second one.
    [$project, $vendor] = warrantyParties();
    $other = accreditedVendorNamed('WTY-OTHER');

    warranties()->register(
        $project, $vendor, '0001', 'Roof membrane',
        Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), User::factory()->create(),
    );

    $second = warranties()->register(
        $project, $other, '0001', 'Lift installation',
        Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), User::factory()->create(),
    );

    expect((int) $second->vendor_id)->toBe($other->getKey());
});

it('refuses a certificate naming an order placed with a different vendor', function () {
    // The order is what the certificate is evidence against. Pointing it at
    // another vendor's order breaks the trace at the only join that explains
    // what was actually bought.
    [$project, $vendor] = warrantyParties();
    [$elsewhere] = receivedGoods();

    expect(fn () => warranties()->register(
        $project, $vendor, 'ACME-WC-8841', 'Roof membrane',
        Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), User::factory()->create(),
        order: $elsewhere,
    ))->toThrow(DomainException::class, 'was not placed with');
});

it('refuses a certificate naming a subcontract on a different project', function () {
    // The SAME vendor, on another project. A certificate that wandered across
    // projects would put one job's coverage on another job's turnover pack.
    [$project, $vendor] = warrantyParties();
    $elsewhere = subcontractOn(constructionProject(), 'SUB-ELSEWHERE', vendor: $vendor);

    expect(fn () => warranties()->register(
        $project, $vendor, 'ACME-WC-8841', 'Structural steel',
        Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), User::factory()->create(),
        subcontract: $elsewhere,
    ))->toThrow(DomainException::class, 'another project');
});

/*
|--------------------------------------------------------------------------
| Claims against the register — F7 joined to F11
|--------------------------------------------------------------------------
*/

it('raises a claim against the certificate and links the two', function () {
    $warranty = registeredWarranty();

    $claim = warranties()->claim(
        $warranty,
        'Membrane delaminated over bay 3 after two weeks of rain.',
        User::factory()->create(),
        failedOn: Carbon::parse('2026-05-19'),
    );

    expect($claim->number)->toStartWith('WC-2026-')
        ->and((int) $claim->warranty_id)->toBe($warranty->getKey())
        ->and((int) $claim->vendor_id)->toBe((int) $warranty->vendor_id)
        ->and($claim->failed_on->toDateString())->toBe('2026-05-19');
});

it('suspends the vendor when a claim is raised through the register', function () {
    // F11's trigger, reached through the new door. A second path that recorded
    // a claim without suspending would disarm the trigger for exactly the
    // claims that have a certificate behind them.
    $warranty = registeredWarranty();

    warranties()->claim($warranty, 'Membrane delaminated.', User::factory()->create());

    $vendor = $warranty->vendor()->sole()->fresh();

    expect($vendor->status)->toBe(VendorStatus::Suspended)
        ->and($vendor->status_reason)->toBe(VendorSuspensionReason::WarrantyClaim);
});

it('refuses a claim for a failure after the coverage lapsed', function () {
    $warranty = registeredWarranty(endsOn: '2026-05-19');
    Carbon::setTestNow('2026-05-25 09:00:00');

    expect(fn () => warranties()->claim(
        $warranty, 'Membrane delaminated.', User::factory()->create(),
        failedOn: Carbon::parse('2026-05-20'),
    ))->toThrow(DomainException::class, 'was not in force');
});

it('accepts a claim filed late for a failure inside the coverage', function () {
    // The operative date is the FAILURE, not the paperwork. A compressor that
    // died the day before expiry is covered however long the claim took to be
    // typed up.
    $warranty = registeredWarranty(endsOn: '2026-05-19');
    Carbon::setTestNow('2026-06-30 09:00:00');

    $claim = warranties()->claim(
        $warranty, 'Membrane delaminated.', User::factory()->create(),
        failedOn: Carbon::parse('2026-05-19'),
    );

    expect($claim->failed_on->toDateString())->toBe('2026-05-19')
        ->and($claim->raised_at->toDateString())->toBe('2026-06-30');
});

it('refuses a claim for a failure before the coverage began', function () {
    $warranty = registeredWarranty(startsOn: '2026-05-18');

    expect(fn () => warranties()->claim(
        $warranty, 'Membrane delaminated.', User::factory()->create(),
        failedOn: Carbon::parse('2026-05-17'),
    ))->toThrow(DomainException::class, 'was not in force');
});

it('refuses a claim for a failure that has not happened yet', function () {
    $warranty = registeredWarranty();

    expect(fn () => warranties()->claim(
        $warranty, 'Membrane will delaminate.', User::factory()->create(),
        failedOn: Carbon::parse('2026-06-01'),
    ))->toThrow(DomainException::class, 'has not happened yet');
});

it('refuses a claim raised against a certificate belonging to another vendor', function () {
    // The service says so before the database does. A foreign key violation is
    // not something a clerk can act on, and this is the message that names what
    // went wrong.
    $warranty = registeredWarranty();
    $other = accreditedVendorNamed('WTY-OTHER');

    expect(fn () => app(ScorecardService::class)->raiseWarrantyClaim(
        $other, null, 'Membrane delaminated.', User::factory()->create(), warranty: $warranty,
    ))->toThrow(DomainException::class, 'was issued by another vendor');
});

it('refuses a claim that points at another vendor\'s certificate, at the database', function () {
    // The composite key. A claim pointing at the wrong promise suspends the
    // wrong company, and the service check is one call away from an importer.
    $warranty = registeredWarranty();
    $claim = warranties()->claim($warranty, 'Membrane delaminated.', User::factory()->create());
    $elsewhere = registeredWarranty(vendor: accreditedVendorNamed('WTY-OTHER'), reference: 'OTHER-1');

    expect(fn () => WarrantyClaim::query()->whereKey($claim->getKey())->update([
        'warranty_id' => $elsewhere->getKey(),
    ]))->toThrow(QueryException::class);
});

it('keeps the claim on the certificate after it is settled', function () {
    // "The record stays; only the trigger is lifted." The register is a history
    // of how a vendor's promises held up, and a settled claim is part of it.
    $warranty = registeredWarranty();
    $claim = warranties()->claim($warranty, 'Membrane delaminated.', User::factory()->create());

    app(ScorecardService::class)
        ->resolveWarrantyClaim($claim, 'Re-laid at the vendor\'s cost.', User::factory()->create());

    expect(warranties()->openClaims($warranty)->count())->toBe(0)
        ->and($warranty->claims()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| What the register is read for
|--------------------------------------------------------------------------
*/

it('reports a warranty in force on its last day and lapsed the day after', function () {
    $warranty = registeredWarranty(startsOn: '2026-05-18', endsOn: '2027-05-18');

    expect(warranties()->inForceOn($warranty, Carbon::parse('2027-05-18')))->toBeTrue()
        ->and(warranties()->inForceOn($warranty, Carbon::parse('2027-05-19')))->toBeFalse()
        ->and(warranties()->inForceOn($warranty, Carbon::parse('2026-05-17')))->toBeFalse();
});

it('lists the certificates lapsing before a date, soonest first', function () {
    // What the close-out report and the DLP review are read from: the
    // certificates about to run out while somebody can still make a claim.
    [$project, $vendor] = warrantyParties();
    $by = User::factory()->create();

    warranties()->register($project, $vendor, 'LATE', 'Lift installation',
        Carbon::parse('2026-05-18'), Carbon::parse('2028-05-18'), $by);
    warranties()->register($project, $vendor, 'SOON', 'Roof membrane',
        Carbon::parse('2026-05-18'), Carbon::parse('2026-08-31'), $by);

    $lapsing = warranties()->expiringBy($project, Carbon::parse('2026-12-31'));

    expect($lapsing->pluck('certificate_reference')->all())->toBe(['SOON']);
});

it('names the subcontracts with no certificate on file', function () {
    // "Collected at turnover" as a query. A subcontract with no warranty in the
    // register is a certificate nobody chased, and P5-04's turnover pack is
    // entitled to refuse to close over it.
    [$project, $vendor] = warrantyParties();
    $steel = subcontractOn($project, 'SUB-STEEL', vendor: $vendor);

    expect(warranties()->uncoveredSubcontracts($project)->pluck('number')->all())
        ->toBe([$steel->number]);

    warranties()->register($project, $vendor, 'ACME-WC-8841', 'Structural steel',
        Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), User::factory()->create(),
        subcontract: $steel);

    expect(warranties()->uncoveredSubcontracts($project->fresh())->count())->toBe(0);
});
