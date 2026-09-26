<?php

use App\Domain\Procurement\ApVoucherStatus;
use App\Filament\Resources\ApVouchers\Pages\ListApVouchers;
use App\Filament\Resources\ThreeWayMatches\Pages\ListThreeWayMatches;
use App\Filament\Resources\VendorAdvances\Pages\ListVendorAdvances;
use App\Filament\Resources\VendorAdvances\VendorAdvancesResource;
use App\Models\ApVoucher;
use App\Models\ThreeWayMatch;
use App\Models\VendorAdvance;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Payables on screen — OS-01
|--------------------------------------------------------------------------
|
| Three-way matches and AP vouchers were list-only: the chain behind them was
| built and tested, and nobody could start it from the panel. Advances had no
| screen at all.
|
| Every act here goes through PayablesService or ThreeWayMatchService. The
| screen types only what a document says — an invoice reference and amount, a
| bank reference, a purpose — and never a computed figure.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('matches an invoice from the screen', function () {
    actingAs(userWithRole('finance-manager'));
    [$po, $report] = acceptedGoods();

    Livewire::test(ListThreeWayMatches::class)
        ->callAction(TestAction::make('matchInvoice'), data: [
            'receiving_report_id' => $report->fresh()->getKey(),
            'invoice_reference' => 'INV-4471',
            'invoice_amount' => '124500.0000',
        ]);

    expect(ThreeWayMatch::query()->where('invoice_reference', 'INV-4471')->sole()->matched)->toBeTrue();
});

it('shows the refusal when a short delivery is billed in full, and writes nothing', function () {
    // THE ONE THAT MATTERS, now reachable from the screen: 400 of 500 arrived
    // and the supplier billed for all 500. The refusal has to reach the clerk.
    actingAs(userWithRole('finance-manager'));
    [$po, $report] = shortDelivery('500.0000', '400.0000');

    Livewire::test(ListThreeWayMatches::class)
        ->callAction(TestAction::make('matchInvoice'), data: [
            'receiving_report_id' => $report->fresh()->getKey(),
            'invoice_reference' => 'INV-SHORT',
            'invoice_amount' => '124500.0000',
        ])
        ->assertNotified();

    expect(ThreeWayMatch::query()->where('invoice_reference', 'INV-SHORT')->exists())->toBeFalse();
});

it('raises a voucher from a matched invoice', function () {
    actingAs(userWithRole('finance-manager'));
    [$po, $report] = acceptedGoods();
    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-9001', '124500.0000');

    Livewire::test(ListApVouchers::class)
        ->callAction(TestAction::make('raiseVoucher'), data: [
            'three_way_match_id' => $match->getKey(),
            'withholding_code' => 'goods',
        ]);

    $voucher = ApVoucher::query()->sole();

    expect($voucher->status)->toBe(ApVoucherStatus::Raised)
        ->and((string) $voucher->gross_amount)->toBe('124500.0000')
        ->and((string) $voucher->withholding_amount)->toBe('1245.0000');
});

it('submits, pays and never pays twice, from the register', function () {
    $voucher = approvedVoucher();
    actingAs(userWithRole('finance-manager'));

    Livewire::test(ListApVouchers::class)
        ->callAction(TestAction::make('payVoucher')->table($voucher), data: [
            'payment_reference' => 'BPI-2026-0512-88',
            'paid_at' => '2026-05-20',
        ]);

    expect($voucher->fresh()->status)->toBe(ApVoucherStatus::Paid);

    // The act is gone from the row once paid, so it cannot be repeated.
    Livewire::test(ListApVouchers::class)
        ->assertActionHidden(TestAction::make('payVoucher')->table($voucher->fresh()))
        ->assertActionHidden(TestAction::make('cancelVoucher')->table($voucher->fresh()));
});

it('offers submit only on a raised voucher', function () {
    $voucher = raisedVoucher('40000.0000');
    actingAs(userWithRole('finance-manager'));

    Livewire::test(ListApVouchers::class)
        ->callAction(TestAction::make('submitVoucher')->table($voucher));

    expect($voucher->fresh()->status)->toBe(ApVoucherStatus::Submitted);

    Livewire::test(ListApVouchers::class)
        ->assertActionHidden(TestAction::make('submitVoucher')->table($voucher->fresh()));
});

it('cancels a voucher with a reason from the register', function () {
    $voucher = raisedVoucher('40000.0000');
    actingAs(userWithRole('finance-manager'));

    Livewire::test(ListApVouchers::class)
        ->callAction(TestAction::make('cancelVoucher')->table($voucher), data: ['reason' => 'Vendor billed the wrong project.']);

    expect($voucher->fresh()->status)->toBe(ApVoucherStatus::Cancelled);
});

it('releases an advance against an approved order', function () {
    actingAs(userWithRole('finance-manager'));
    [$po] = orderAwaitingCountersignature();

    Livewire::test(ListVendorAdvances::class)
        ->callAction(TestAction::make('releaseAdvance'), data: [
            'purchase_order_id' => $po->getKey(),
            'amount' => '50000.0000',
            'purpose' => 'Mobilisation of the supply.',
        ]);

    expect((string) VendorAdvance::query()->sole()->amount)->toBe('50000.0000');
});

it('shows the refusal when an advance would exceed the order', function () {
    actingAs(userWithRole('finance-manager'));
    [$po] = orderAwaitingCountersignature();

    Livewire::test(ListVendorAdvances::class)
        ->callAction(TestAction::make('releaseAdvance'), data: [
            'purchase_order_id' => $po->getKey(),
            'amount' => '500000.0000',
            'purpose' => 'Everything up front.',
        ])
        ->assertNotified();

    expect(VendorAdvance::query()->count())->toBe(0);
});

it('keeps the advances register away from roles with no business in it', function (string $role, int $status) {
    actingAs(userWithRole($role));

    get(VendorAdvancesResource::getUrl('index'))->assertStatus($status);
})->with([
    'finance' => ['finance-manager', 200],
    'procurement head' => ['procurement-head', 200],
    'foreman' => ['foreman', 403],
    'hr' => ['hr-manager', 403],
]);
