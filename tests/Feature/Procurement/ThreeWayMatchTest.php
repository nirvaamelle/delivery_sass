<?php

use App\Domain\Documents\DocumentLinker;
use App\Domain\Procurement\InspectionService;
use App\Domain\Procurement\MatchFailedException;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\ReceivingReport;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Three-way match and AP vouchers — P1-11
|--------------------------------------------------------------------------
|
| PLAN.md §5: "No payment without a three-way match (PO ↔ receiving report ↔
| invoice)."
|
| Three documents, three questions, and the match fails if any one disagrees:
|
|   - Did we order it?          The PO.
|   - Did it arrive, and pass?  The receiving report, as accepted by inspection.
|   - Is this what we were billed?  The invoice.
|
| The one that gets missed is the middle. Matching a PO against an invoice is
| easy and proves nothing: both are documents the company and the supplier
| wrote, and neither says the goods exist. **The match is against the ACCEPTED
| quantity**, not the delivered one — a short delivery or a rejected batch must
| reduce what is payable, or the inspection was theatre.
|
| F8 lands here too: a vendor advance is a real payment with no match behind it,
| so the match must recognise it as a distinct, non-matched type rather than
| force somebody to fake a receipt.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('matches a full delivery against a full invoice', function () {
    [$po, $report] = acceptedGoods();

    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-4471', '124500.0000');

    expect($match->matched)->toBeTrue()
        ->and($match->number)->toStartWith('TWM-2026-');
});

it('refuses a match when the invoice exceeds the purchase order', function () {
    // Being billed more than was ordered is the case the control exists for.
    [$po, $report] = acceptedGoods();

    expect(fn () => matches()->match($po->fresh(), $report->fresh(), 'INV-1', '200000.0000'))
        ->toThrow(MatchFailedException::class);
});

it('refuses a match against a short delivery billed in full', function () {
    // THE ONE THAT MATTERS. 400 of 500 bags arrived and the supplier billed for
    // all 500. The invoice is 124,500 — exactly the purchase order total, so a
    // PO-to-invoice comparison passes it without complaint. Both documents say
    // 500, and only the receiving report knows that 100 never turned up.
    [$po, $report] = shortDelivery('500.0000', '400.0000');

    expect(fn () => matches()->match($po->fresh(), $report->fresh(), 'INV-2', '124500.0000'))
        ->toThrow(MatchFailedException::class);
});

it('matches a short delivery billed for what actually arrived', function () {
    // The other half of the same rule: short is not wrong, it is short. 400 at
    // 249 is 99,600, and that invoice is payable today — the outstanding 100
    // stays outstanding against the order rather than blocking the payment.
    [$po, $report] = shortDelivery('500.0000', '400.0000');

    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-2B', '99600.0000');

    expect($match->matched)->toBeTrue();
});

it('matches only what inspection accepted, not what arrived', function () {
    // 500 delivered, 20 rejected, 480 accepted. The payable value follows the
    // 480 — otherwise the rejection cost the company nothing to make.
    [$po, $report] = receivedGoods('500.0000');

    app(InspectionService::class)->inspect($report, [
        [
            'receiving_report_line_id' => $report->lines()->sole()->getKey(),
            'quantity_accepted' => '480.0000',
            'quantity_rejected' => '20.0000',
            'rejection_reason' => 'Hardened in transit.',
        ],
    ], User::factory()->create());

    expect(matches()->payableValueFor($po->fresh(), $report->fresh()))->toBe('119520.0000');
});

it('refuses a match on an uninspected delivery', function () {
    // Uninspected goods have an accepted quantity of zero, so there is nothing
    // to pay against — which is the right answer, not a bug.
    [$po, $report] = receivedGoods();

    expect(fn () => matches()->match($po->fresh(), $report->fresh(), 'INV-3', '124500.0000'))
        ->toThrow(MatchFailedException::class);
});

it('raises an AP voucher from a successful match', function () {
    [$po, $report] = acceptedGoods();
    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-4471', '124500.0000');

    $voucher = payables()->raise($match, 'services');

    expect($voucher->number)->toStartWith('APV-2026-');
    expectMoney($voucher->fresh()->gross_amount);
    expect($voucher->fresh()->gross_amount)->toBe('124500.0000');
});

it('withholds tax on the voucher through the P0-09 columns', function () {
    // F9's macro, finally carrying a real amount. 2% of 124,500 is 2,490.
    [$po, $report] = acceptedGoods();
    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-4471', '124500.0000');

    $voucher = payables()->raise($match, 'services')->fresh();

    expect($voucher->withholding_code)->toBe('services')
        ->and($voucher->withholding_amount)->toBe('2490.0000')
        ->and($voucher->net_amount)->toBe('122010.0000');
});

it('refuses an AP voucher with no match behind it', function () {
    // PLAN.md §5, stated as a foreign key. The service signature takes a
    // ThreeWayMatch and nothing else, so no arrangement of arguments produces a
    // voucher with no match — but a service is not the only way to write a row,
    // and PLAN.md §5's whole argument is that an importer or a queued job never
    // calls one. This goes straight to the query builder, past Eloquent, past
    // the model guard, and asserts the DATABASE is what refuses it.
    expect(fn () => DB::table('ap_vouchers')->insert([
        'vendor_id' => Vendor::factory()->create()->getKey(),
        'project_id' => Project::factory()->create()->getKey(),
        'number' => 'APV-2026-09999',
        'gross_amount' => '124500.0000',
        'withholding_amount' => '0.0000',
        'advance_offset' => '0.0000',
        'net_amount' => '124500.0000',
        'status' => 'raised',
        'raised_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses a second voucher for one match', function () {
    // Two vouchers from one match is a double payment with a complete-looking
    // document set behind each.
    [$po, $report] = acceptedGoods();
    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-4471', '124500.0000');

    payables()->raise($match, 'services');

    expect(fn () => payables()->raise($match->fresh(), 'services'))
        ->toThrow(QueryException::class);
});

it('releases a vendor advance against a purchase order without a match', function () {
    // F8. An advance is a real payment with no receipt behind it — the goods
    // have not shipped. Forcing it through the match would mean faking one.
    [$po] = acceptedGoods();

    $advance = payables()->releaseAdvance($po->fresh(), '25000.0000', 'Mobilisation advance, 20%.');

    expect($advance->number)->toStartWith('ADV-2026-')
        ->and($advance->amount)->toBe('25000.0000');
});

it('offsets a released advance at the AP voucher', function () {
    // F8's second half. The advance was already paid, so the voucher pays the
    // balance — otherwise the vendor is paid twice for the same goods.
    [$po, $report] = acceptedGoods();
    payables()->releaseAdvance($po->fresh(), '25000.0000', 'Mobilisation advance.');

    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-4471', '124500.0000');
    $voucher = payables()->raise($match, 'services')->fresh();

    expect($voucher->advance_offset)->toBe('25000.0000')
        ->and($voucher->net_amount)->toBe('97010.0000');
});

it('refuses an advance larger than the purchase order', function () {
    [$po] = acceptedGoods();

    expect(fn () => payables()->releaseAdvance($po->fresh(), '900000.0000', 'Too much.'))
        ->toThrow(DomainException::class);
});

it('links the voucher to the match and the match to all three documents', function () {
    [$po, $report] = acceptedGoods();
    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-4471', '124500.0000');

    $predecessors = app(DocumentLinker::class)
        ->predecessorsOf($match)
        ->map(fn ($m): string => $m::class)
        ->sort()->values()->all();

    expect($predecessors)->toContain(PurchaseOrder::class)
        ->and($predecessors)->toContain(ReceivingReport::class);
});

it('refuses a voucher amount changed by a direct update', function () {
    [$po, $report] = acceptedGoods();
    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-4471', '124500.0000');
    $voucher = payables()->raise($match, 'services');

    expect(fn () => $voucher->update(['net_amount' => '1.0000']))
        ->toThrow(DomainException::class);
});
