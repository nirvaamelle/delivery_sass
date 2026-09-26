<?php

use App\Domain\Vendors\BondType;
use App\Domain\Vendors\ValidationVerdict;
use App\Domain\Vendors\VendorService;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Validation visits and bonds — P1-02, closes F15
|--------------------------------------------------------------------------
|
| F15 adds two records the deck implies but never names, and the finding's whole
| point is that they expire on their own clocks:
|
|   - `vendor_validation_visits` — dated, with a verdict. Somebody went to the
|     address on the accreditation form and reported what was there.
|   - `vendor_bonds` — surety, performance and warranty bonds, each with its own
|     expiry, independent of the accreditation.
|
| "Expired bond blocks a subcontract award; expired accreditation blocks an
| RFQ." Two different documents gating two different acts. Treating them as one
| status is how a vendor with a current accreditation and a lapsed performance
| bond gets awarded a subcontract — which is precisely the exposure a bond
| exists to cover.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('records a validation visit with its verdict', function () {
    $vendor = Vendor::factory()->create();
    $officer = User::factory()->create();

    $visit = app(VendorService::class)->recordValidationVisit(
        $vendor,
        Carbon::parse('2026-05-02'),
        ValidationVerdict::Passed,
        $officer,
        'Yard and equipment as declared.',
    );

    expect($visit->verdict)->toBe(ValidationVerdict::Passed)
        ->and($visit->visited_at->toDateString())->toBe('2026-05-02')
        ->and($visit->visited_by_user_id)->toBe($officer->id)
        ->and($vendor->validationVisits()->count())->toBe(1);
});

it('keeps every visit rather than overwriting the last', function () {
    // A vendor that failed a visit and passed the next one has a history worth
    // reading. Overwriting keeps only the flattering half.
    $vendor = Vendor::factory()->create();
    $officer = User::factory()->create();
    $service = app(VendorService::class);

    $service->recordValidationVisit($vendor, Carbon::parse('2025-02-01'), ValidationVerdict::Failed, $officer);
    $service->recordValidationVisit($vendor, Carbon::parse('2026-05-02'), ValidationVerdict::Passed, $officer);

    expect($vendor->validationVisits()->count())->toBe(2)
        ->and($service->latestValidationVisit($vendor)?->verdict)->toBe(ValidationVerdict::Passed);
});

it('registers a bond with its own expiry', function () {
    $vendor = Vendor::factory()->create();

    $bond = app(VendorService::class)->registerBond(
        $vendor,
        BondType::Performance,
        '500000.0000',
        Carbon::parse('2026-01-01'),
        Carbon::parse('2027-01-01'),
        'BOND-2026-0042',
    );

    expect($bond->type)->toBe(BondType::Performance)
        ->and($bond->reference)->toBe('BOND-2026-0042');

    expectMoney($bond->fresh()->amount);
});

it('treats a bond inside its window as valid', function () {
    $vendor = Vendor::factory()->create();
    $service = app(VendorService::class);

    $service->registerBond($vendor, BondType::Performance, '500000.0000', Carbon::parse('2026-01-01'), Carbon::parse('2027-01-01'));

    expect($service->hasValidBond($vendor, BondType::Performance))->toBeTrue();
});

it('treats a lapsed bond as invalid', function () {
    $vendor = Vendor::factory()->create();
    $service = app(VendorService::class);

    $service->registerBond($vendor, BondType::Performance, '500000.0000', Carbon::parse('2024-01-01'), Carbon::parse('2025-01-01'));

    expect($service->hasValidBond($vendor, BondType::Performance))->toBeFalse();
});

it('does not accept one bond type in place of another', function () {
    // A surety bond is not a performance bond. They cover different things, and
    // accepting either would defeat the point of asking for a specific one.
    $vendor = Vendor::factory()->create();
    $service = app(VendorService::class);

    $service->registerBond($vendor, BondType::Surety, '500000.0000', Carbon::parse('2026-01-01'), Carbon::parse('2027-01-01'));

    expect($service->hasValidBond($vendor, BondType::Surety))->toBeTrue()
        ->and($service->hasValidBond($vendor, BondType::Performance))->toBeFalse();
});

it('treats a vendor with no bond at all as unbonded', function () {
    expect(app(VendorService::class)->hasValidBond(Vendor::factory()->create(), BondType::Performance))
        ->toBeFalse();
});

it('keeps bond validity independent of accreditation', function () {
    // The heart of F15. A current accreditation and a lapsed performance bond
    // is exactly the combination that gets a subcontract awarded against no
    // cover — so the two clocks must not be read as one.
    $vendor = Vendor::factory()->create();
    $service = app(VendorService::class);

    $service->accredit($vendor, Carbon::parse('2026-05-01'));
    $service->registerBond($vendor, BondType::Performance, '500000.0000', Carbon::parse('2024-01-01'), Carbon::parse('2025-01-01'));

    expect($service->isAccredited($vendor->fresh()))->toBeTrue()
        ->and($service->hasValidBond($vendor, BondType::Performance))->toBeFalse();
});

it('accepts a renewed bond alongside the lapsed one it replaces', function () {
    $vendor = Vendor::factory()->create();
    $service = app(VendorService::class);

    $service->registerBond($vendor, BondType::Performance, '500000.0000', Carbon::parse('2024-01-01'), Carbon::parse('2025-01-01'));
    $service->registerBond($vendor, BondType::Performance, '750000.0000', Carbon::parse('2026-01-01'), Carbon::parse('2027-01-01'));

    expect($service->hasValidBond($vendor, BondType::Performance))->toBeTrue()
        ->and($vendor->bonds()->count())->toBe(2);
});
