<?php

use App\Domain\Vendors\VendorStatus;
use App\Domain\Vendors\VendorSuspensionReason;
use App\Filament\Resources\Vendors\Pages\ListVendors;
use App\Filament\Resources\Vendors\VendorResource;
use App\Models\Vendor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Vendors screen — P1-03a
|--------------------------------------------------------------------------
|
| Pulled forward from P1-16 so the procurement chain is visible while it is
| being built rather than only at the end of the phase.
|
| The rule from P0-14 holds here and is worth restating, because this screen has
| more ways to break it: **the UI does not change vendor standing.** Accrediting,
| suspending, clearing and removing all call VendorService. If the screen wrote
| status directly it would skip the terminal-removal check, and a removed vendor
| could be quietly restored through a form — which is the one thing PLAN.md §5
| says must never happen.
|
| The screen also must not become a way to read what the database is deliberately
| hiding: bank details are encrypted at rest, so they are write-only here.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');
    // Screens are authorised by role (config/access.php). This suite tests what
    // the screens show, so it signs in as the administrator, who sees them all.
    actingAs(panelUser());
});

afterEach(function () {
    Carbon::setTestNow();
});

it('renders the vendor register', function () {
    get(VendorResource::getUrl('index'))->assertSuccessful();
});

it('lists vendors with their standing', function () {
    $vendor = Vendor::factory()->create(['code' => 'VEN-0001', 'name' => 'Cement Supplier Inc']);

    Livewire::test(ListVendors::class)->assertCanSeeTableRecords([$vendor]);
});

it('renders the create form', function () {
    get(VendorResource::getUrl('create'))->assertSuccessful();
});

it('accredits a vendor through the domain service', function () {
    // Not by setting a column. The service is what knows an accreditation runs
    // twelve months and what refuses to revive a removed vendor.
    $vendor = Vendor::factory()->create();

    Livewire::test(ListVendors::class)
        ->callAction(
            TestAction::make('accredit')->table($vendor),
            data: ['accredited_at' => '2026-05-01', 'certificate_reference' => 'ACC-2026-001'],
        );

    $vendor->refresh();

    expect($vendor->status)->toBe(VendorStatus::Accredited)
        ->and($vendor->accreditations()->count())->toBe(1)
        ->and($vendor->accreditations()->first()->expires_at->toDateString())->toBe('2027-05-01');
});

it('will not suspend a vendor without a reason', function () {
    // F11: a suspension with no recorded reason is the one nobody can explain
    // later, and "suspended until cleared by management" needs to say why.
    $vendor = Vendor::factory()->create(['status' => VendorStatus::Accredited]);

    Livewire::test(ListVendors::class)
        ->callAction(TestAction::make('suspend')->table($vendor), data: ['reason' => null])
        ->assertHasActionErrors(['reason']);

    expect($vendor->fresh()->status)->toBe(VendorStatus::Accredited);
});

it('suspends a vendor and records who did it', function () {
    // Procurement, not admin: the suspension records who did it, and the vendor
    // screen is procurement's. The least role that can do the job.
    $actor = userWithRole('procurement-head');
    actingAs($actor);

    $vendor = Vendor::factory()->create(['status' => VendorStatus::Accredited]);

    Livewire::test(ListVendors::class)
        ->callAction(
            TestAction::make('suspend')->table($vendor),
            data: [
                'reason' => VendorSuspensionReason::LateDeliveries->value,
                'notes' => 'Two late deliveries this quarter.',
            ],
        );

    $vendor->refresh();

    expect($vendor->status)->toBe(VendorStatus::Suspended)
        ->and($vendor->status_reason)->toBe(VendorSuspensionReason::LateDeliveries)
        ->and($vendor->status_changed_by_user_id)->toBe($actor->id);
});

it('does not offer to clear a vendor that is not suspended', function () {
    $vendor = Vendor::factory()->create(['status' => VendorStatus::Accredited]);

    Livewire::test(ListVendors::class)
        ->assertActionHidden(TestAction::make('clearSuspension')->table($vendor));
});

it('does not offer to clear a removed vendor', function () {
    // Removal is terminal. The service refuses it; the screen should not even
    // present the button, because an action that always fails is a bug report
    // waiting to be filed.
    $vendor = Vendor::factory()->create(['status' => VendorStatus::Removed]);

    Livewire::test(ListVendors::class)
        ->assertActionHidden(TestAction::make('clearSuspension')->table($vendor));
});

it('clears a suspension through the service', function () {
    $vendor = Vendor::factory()->create(['status' => VendorStatus::Suspended]);

    Livewire::test(ListVendors::class)
        ->callAction(
            TestAction::make('clearSuspension')->table($vendor),
            data: ['notes' => 'Corrective action accepted.'],
        );

    expect($vendor->fresh()->status)->toBe(VendorStatus::Accredited);
});

it('never shows a vendor bank account number', function () {
    // Encrypted at rest per PLAN.md §3. A register screen that decrypts and
    // prints it hands back exactly what the encryption was for — and this is a
    // panel every foreman can open.
    $vendor = Vendor::factory()->create([
        'code' => 'VEN-BANK',
        'bank_account_number' => '1234-5678-9012',
    ]);

    Livewire::test(ListVendors::class)
        ->assertCanSeeTableRecords([$vendor])
        ->assertDontSee('1234-5678-9012');

    get(VendorResource::getUrl('edit', ['record' => $vendor]))
        ->assertSuccessful()
        ->assertDontSee('1234-5678-9012');
});
