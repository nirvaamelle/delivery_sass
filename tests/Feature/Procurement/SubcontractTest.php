<?php

use App\Domain\Procurement\ExpiredBondException;
use App\Domain\Procurement\SubcontractStatus;
use App\Domain\Procurement\VendorNotEligibleException;
use App\Domain\Vendors\BondType;
use App\Domain\Vendors\VendorService;
use App\Domain\Vendors\VendorSuspensionReason;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Subcontracts — P1-07, closes the second half of F15
|--------------------------------------------------------------------------
|
| F15: "Expired bond blocks a subcontract award; expired accreditation blocks
| an RFQ." P1-03 built the second clause. This is the first.
|
| The pairing is the point. A subcontract is not a purchase — the exposure is
| performance over months, so the document that matters is the performance bond
| rather than the accreditation certificate. P1-02 gave bonds their own expiry
| precisely so a vendor can be currently accredited and currently unbonded, and
| this is where that distinction stops being academic.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('awards a subcontract to an accredited, bonded subcontractor', function () {
    $subcontract = subcontracts()->award(
        Project::factory()->create(),
        bondedSubcon(),
        'Structural steel erection',
        '2500000.0000',
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-12-31'),
    );

    expect($subcontract->number)->toStartWith('SC-2026-')
        ->and($subcontract->status)->toBe(SubcontractStatus::Awarded);

    expectMoney($subcontract->fresh()->contract_amount);
});

it('refuses a subcontract to a vendor whose performance bond has lapsed', function () {
    // THE CONTROL. The vendor is accredited and looks entirely ordinary in the
    // register; the bond expired four months ago, and the exposure a subcontract
    // creates is exactly what the bond was there to cover.
    $vendor = Vendor::factory()->create(['code' => 'SUB-LAPSED']);
    $vendors = app(VendorService::class);

    $vendors->accredit($vendor, Carbon::parse('2026-05-01'));
    $vendors->registerBond(
        $vendor, BondType::Performance, '500000.0000',
        Carbon::parse('2024-01-01'), Carbon::parse('2025-01-01'),
    );

    expect(fn () => subcontracts()->award(
        Project::factory()->create(), $vendor->fresh(),
        'Structural steel', '2500000.0000',
        Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'),
    ))->toThrow(ExpiredBondException::class);
});

it('refuses a subcontract to a vendor with no bond at all', function () {
    $vendor = Vendor::factory()->create(['code' => 'SUB-NOBOND']);
    app(VendorService::class)->accredit($vendor, Carbon::parse('2026-05-01'));

    expect(fn () => subcontracts()->award(
        Project::factory()->create(), $vendor->fresh(),
        'Steel', '2500000.0000',
        Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'),
    ))->toThrow(ExpiredBondException::class);
});

it('does not accept a surety bond in place of a performance bond', function () {
    // They cover different exposures. A surety bond guarantees the bid; it says
    // nothing about whether the work gets finished.
    $vendor = Vendor::factory()->create(['code' => 'SUB-SURETY']);
    $vendors = app(VendorService::class);

    $vendors->accredit($vendor, Carbon::parse('2026-05-01'));
    $vendors->registerBond(
        $vendor, BondType::Surety, '500000.0000',
        Carbon::parse('2026-01-01'), Carbon::parse('2027-01-01'),
    );

    expect(fn () => subcontracts()->award(
        Project::factory()->create(), $vendor->fresh(),
        'Steel', '2500000.0000',
        Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'),
    ))->toThrow(ExpiredBondException::class);
});

it('refuses a subcontract to a suspended subcontractor even with a live bond', function () {
    // Both conditions, independently. A bond does not rehabilitate a vendor
    // suspended for late deliveries.
    $vendor = bondedSubcon('SUB-SUSPENDED');
    app(VendorService::class)->suspend(
        $vendor, VendorSuspensionReason::LateDeliveries, User::factory()->create(),
    );

    expect(fn () => subcontracts()->award(
        Project::factory()->create(), $vendor->fresh(),
        'Steel', '2500000.0000',
        Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'),
    ))->toThrow(VendorNotEligibleException::class);
});

it('refuses a subcontract whose bond expires before the works finish', function () {
    // A bond that lapses mid-contract covers the easy half. The exposure is
    // largest at the end, when the work is late and the money is spent.
    $vendor = Vendor::factory()->create(['code' => 'SUB-SHORT']);
    $vendors = app(VendorService::class);

    $vendors->accredit($vendor, Carbon::parse('2026-05-01'));
    $vendors->registerBond(
        $vendor, BondType::Performance, '500000.0000',
        Carbon::parse('2026-01-01'), Carbon::parse('2026-08-31'),
    );

    expect(fn () => subcontracts()->award(
        Project::factory()->create(), $vendor->fresh(),
        'Steel', '2500000.0000',
        Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'),
    ))->toThrow(ExpiredBondException::class);
});

it('refuses a subcontract that ends before it starts', function () {
    expect(fn () => subcontracts()->award(
        Project::factory()->create(), bondedSubcon(),
        'Steel', '2500000.0000',
        Carbon::parse('2026-12-31'), Carbon::parse('2026-06-01'),
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses a zero-value subcontract', function () {
    expect(fn () => subcontracts()->award(
        Project::factory()->create(), bondedSubcon(),
        'Steel', '0.0000',
        Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'),
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses a contract amount changed by a direct update', function () {
    $subcontract = subcontracts()->award(
        Project::factory()->create(), bondedSubcon(),
        'Steel', '2500000.0000',
        Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'),
    );

    expect(fn () => $subcontract->update(['contract_amount' => '1.0000']))
        ->toThrow(DomainException::class);
});
