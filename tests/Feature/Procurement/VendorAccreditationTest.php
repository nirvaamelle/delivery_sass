<?php

use App\Domain\Vendors\VendorService;
use App\Domain\Vendors\VendorStatus;
use App\Domain\Vendors\VendorSuspensionReason;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Vendor accreditation — P1-01, closes F11
|--------------------------------------------------------------------------
|
| PLAN.md §5, two controls:
|
|   "Accreditation expires at 12 months; expired vendors cannot receive an RFQ."
|   "Two late deliveries in a quarter, or rejection rate above 5%, suspends a
|    vendor. Falsified documents — immediate removal."
|
| F11 turns vendor status from a boolean into an enum with a reason and a
| clearing user, because "suspended until cleared by management" is a sentence
| about accountability: who suspended, why, and who is allowed to lift it. A
| boolean records none of that, and the vendor whose suspension was quietly
| reversed is exactly the one anybody would later want to ask about.
|
| Expiry is derived, never stored as a flag. A stored `is_expired` boolean is
| only as true as the last job that ran — and the day that job fails is the day
| an expired vendor receives an RFQ.
|
| PLACEHOLDER: Part D item 17 — which role clears a suspension, and whether
| clearing resets the scorecard window, is unanswered. Clearing is recorded
| against a user here; who is *permitted* to clear becomes a permission check
| when the answer lands.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function vendors(): VendorService
{
    return app(VendorService::class);
}

it('accredits a vendor for twelve months', function () {
    $vendor = Vendor::factory()->create();

    $accreditation = vendors()->accredit($vendor, Carbon::parse('2026-05-01'));

    expect($accreditation->expires_at->toDateString())->toBe('2027-05-01')
        ->and($vendor->fresh()->status)->toBe(VendorStatus::Accredited);
});

it('treats an accreditation inside its window as current', function () {
    $vendor = Vendor::factory()->create();
    vendors()->accredit($vendor, Carbon::parse('2026-05-01'));

    expect(vendors()->isAccredited($vendor->fresh()))->toBeTrue();
});

it('treats an accreditation past twelve months as expired', function () {
    // Derived from the date, not from a stored flag. A flag is only as true as
    // the last job that ran, and the day that job fails is the day an expired
    // vendor receives an RFQ.
    $vendor = Vendor::factory()->create();
    vendors()->accredit($vendor, Carbon::parse('2025-01-01'));

    expect(vendors()->isAccredited($vendor->fresh()))->toBeFalse();
});

it('treats a vendor accredited exactly twelve months ago as still current', function () {
    // The boundary. Expiry on the anniversary is a cliff somebody will stand
    // on, so which side they land on is a decision, not an accident.
    $vendor = Vendor::factory()->create();
    vendors()->accredit($vendor, Carbon::parse('2025-05-14'));

    expect(vendors()->isAccredited($vendor->fresh()))->toBeTrue();
});

it('treats a vendor with no accreditation at all as not accredited', function () {
    // Absence is not permission. A vendor record can exist long before anyone
    // has checked its papers.
    expect(vendors()->isAccredited(Vendor::factory()->create()))->toBeFalse();
});

it('renews an accreditation from the new date', function () {
    $vendor = Vendor::factory()->create();
    vendors()->accredit($vendor, Carbon::parse('2025-01-01'));

    vendors()->accredit($vendor, Carbon::parse('2026-05-01'));

    expect(vendors()->isAccredited($vendor->fresh()))->toBeTrue()
        ->and($vendor->accreditations()->count())->toBe(2);
});

it('suspends a vendor with a reason and the user who did it', function () {
    // F11. "Suspended until cleared by management" is a sentence about
    // accountability — a boolean records none of it.
    $vendor = Vendor::factory()->create();
    vendors()->accredit($vendor, Carbon::parse('2026-05-01'));

    $officer = User::factory()->create(['name' => 'Procurement Head']);

    vendors()->suspend($vendor, VendorSuspensionReason::LateDeliveries, $officer, 'Two late deliveries this quarter.');

    $vendor->refresh();

    expect($vendor->status)->toBe(VendorStatus::Suspended)
        ->and($vendor->status_reason)->toBe(VendorSuspensionReason::LateDeliveries)
        ->and($vendor->status_notes)->toBe('Two late deliveries this quarter.')
        ->and($vendor->status_changed_by_user_id)->toBe($officer->id)
        ->and($vendor->status_changed_at)->not->toBeNull();
});

it('treats a suspended vendor as not accredited even inside the window', function () {
    // The control that matters. A live accreditation certificate does not
    // outrank a suspension — otherwise suspending a vendor would change nothing
    // about what they can be sent.
    $vendor = Vendor::factory()->create();
    vendors()->accredit($vendor, Carbon::parse('2026-05-01'));
    vendors()->suspend($vendor, VendorSuspensionReason::LateDeliveries, User::factory()->create());

    expect(vendors()->isAccredited($vendor->fresh()))->toBeFalse();
});

it('clears a suspension and records who lifted it', function () {
    $vendor = Vendor::factory()->create();
    vendors()->accredit($vendor, Carbon::parse('2026-05-01'));
    vendors()->suspend($vendor, VendorSuspensionReason::RejectionRate, User::factory()->create());

    $manager = User::factory()->create(['name' => 'Managing Director']);
    vendors()->clearSuspension($vendor, $manager, 'Corrective action accepted.');

    $vendor->refresh();

    expect($vendor->status)->toBe(VendorStatus::Accredited)
        ->and($vendor->status_changed_by_user_id)->toBe($manager->id)
        ->and(vendors()->isAccredited($vendor))->toBeTrue();
});

it('removes a vendor for falsified documents', function () {
    $vendor = Vendor::factory()->create();
    vendors()->accredit($vendor, Carbon::parse('2026-05-01'));

    vendors()->remove($vendor, User::factory()->create(), 'Falsified mayor\'s permit.');

    expect($vendor->fresh()->status)->toBe(VendorStatus::Removed);
});

it('refuses to clear a removed vendor', function () {
    // Removal is terminal. PLAN.md §5: falsified documents mean immediate
    // removal — a route back would make that sentence meaningless.
    $vendor = Vendor::factory()->create();
    vendors()->remove($vendor, User::factory()->create(), 'Falsified documents.');

    expect(fn () => vendors()->clearSuspension($vendor, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('rejects two vendors sharing a code', function () {
    Vendor::factory()->create(['code' => 'VEN-0001']);

    expect(fn () => Vendor::factory()->create(['code' => 'VEN-0001']))
        ->toThrow(QueryException::class);
});

it('encrypts vendor bank details at rest', function () {
    // PLAN.md §3: sensitive columns encrypted in the FIRST migration that
    // creates them, never later. Vendor bank details are named explicitly.
    $vendor = Vendor::factory()->create(['bank_account_number' => '1234-5678-9012']);

    $raw = DB::table('vendors')->where('id', $vendor->getKey())->value('bank_account_number');

    expect($vendor->fresh()->bank_account_number)->toBe('1234-5678-9012')
        ->and($raw)->not->toBe('1234-5678-9012');
});

it('lists only vendors eligible to receive an RFQ', function () {
    // What P1-03's RFQ gate will consult.
    $good = Vendor::factory()->create(['code' => 'VEN-GOOD']);
    vendors()->accredit($good, Carbon::parse('2026-05-01'));

    $expired = Vendor::factory()->create(['code' => 'VEN-EXPIRED']);
    vendors()->accredit($expired, Carbon::parse('2024-01-01'));

    $suspended = Vendor::factory()->create(['code' => 'VEN-SUSPENDED']);
    vendors()->accredit($suspended, Carbon::parse('2026-05-01'));
    vendors()->suspend($suspended, VendorSuspensionReason::LateDeliveries, User::factory()->create());

    expect(vendors()->eligibleForRfq()->pluck('code')->all())->toBe(['VEN-GOOD']);
});
