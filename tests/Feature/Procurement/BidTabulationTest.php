<?php

use App\Domain\Documents\DocumentLinker;
use App\Domain\Procurement\InsufficientQuotesException;
use App\Domain\Procurement\QuoteService;
use App\Domain\Procurement\RfqService;
use App\Domain\Procurement\TabulationService;
use App\Domain\Requisitions\RequisitionStatus;
use App\Domain\Vendors\VendorService;
use App\Models\PurchaseRequisition;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Quotes and bid tabulation — P1-04
|--------------------------------------------------------------------------
|
| PLAN.md §5: "Three quotes minimum before award; sole source needs written
| justification approved one level up."
|
| P1-03 enforced the minimum on who was ASKED. This enforces it on who actually
| ANSWERED, and the difference is the whole point: inviting three vendors and
| receiving one quote is not a canvass. The deck's abstract of canvass is a
| comparison, and a comparison of one is a decision already made.
|
| The tabulation recommends the lowest responsive quote and records what it
| compared. Recommending is not awarding — the award is the tiered approval in
| P1-06, and keeping them separate is what stops "the system chose it" from
| standing in for a signature.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function quotes(): QuoteService
{
    return app(QuoteService::class);
}

function tabulations(): TabulationService
{
    return app(TabulationService::class);
}

/**
 * An issued RFQ with three accredited vendors invited.
 *
 * @return array{0: Rfq, 1: array<int, Vendor>}
 */
function issuedRfq(): array
{
    $pr = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Approved]);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'));

    $vendors = [];

    foreach (['VEN-A', 'VEN-B', 'VEN-C'] as $code) {
        $vendor = Vendor::factory()->create(['code' => $code]);
        app(VendorService::class)->accredit($vendor, Carbon::parse('2026-05-01'));
        $vendor = $vendor->fresh();

        app(RfqService::class)->invite($rfq, $vendor);
        $vendors[] = $vendor;
    }

    app(RfqService::class)->issue($rfq);

    return [$rfq->fresh(), $vendors];
}

it('records a quote against an issued RFQ', function () {
    [$rfq, $vendors] = issuedRfq();

    $quote = quotes()->record($rfq, $vendors[0], [
        ['description' => 'Portland cement, 500 bags', 'quantity' => '500.0000', 'unit_price' => '260.0000'],
    ]);

    expect($quote->vendor_id)->toBe($vendors[0]->getKey());
    expectMoney($quote->fresh()->total_amount);
    expect($quote->fresh()->total_amount)->toBe('130000.0000');
});

it('totals quote lines exactly', function () {
    // Quantity times unit price, summed. Done in bcmath: a float here puts a
    // rounding error into the number the award is decided on.
    [$rfq, $vendors] = issuedRfq();

    $quote = quotes()->record($rfq, $vendors[0], [
        ['description' => 'Cement', 'quantity' => '333.0000', 'unit_price' => '260.3333'],
        ['description' => 'Rebar', 'quantity' => '12.5000', 'unit_price' => '1499.9999'],
    ]);

    // 333 x 260.3333 = 86,690.9889 exactly.
    // 12.5 x 1,499.9999 = 18,749.99875 - a half at the fifth decimal, which
    // rounds up. bcmul at scale 4 would have truncated it to ...87.
    expect($quote->fresh()->total_amount)->toBe('105440.9877');
});

it('refuses a quote from a vendor that was never invited', function () {
    // An uninvited quote is one nobody solicited, and it would sit in the
    // canvass looking like part of the comparison.
    [$rfq] = issuedRfq();

    $stranger = Vendor::factory()->create(['code' => 'VEN-STRANGER']);
    app(VendorService::class)->accredit($stranger, Carbon::parse('2026-05-01'));

    expect(fn () => quotes()->record($rfq, $stranger->fresh(), [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '100.0000'],
    ]))->toThrow(DomainException::class);
});

it('refuses a quote against an RFQ that has not been issued', function () {
    $pr = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Approved]);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'));

    $vendor = Vendor::factory()->create();
    app(VendorService::class)->accredit($vendor, Carbon::parse('2026-05-01'));
    app(RfqService::class)->invite($rfq, $vendor->fresh());

    expect(fn () => quotes()->record($rfq, $vendor->fresh(), [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '100.0000'],
    ]))->toThrow(DomainException::class);
});

it('refuses a second quote from the same vendor', function () {
    // A revised price replaces a quote; it does not sit beside it. Two quotes
    // from one vendor would let the same bidder occupy two places in a
    // three-way comparison.
    [$rfq, $vendors] = issuedRfq();

    quotes()->record($rfq, $vendors[0], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '100.0000'],
    ]);

    expect(fn () => quotes()->record($rfq, $vendors[0], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '90.0000'],
    ]))->toThrow(QueryException::class);
});

it('refuses a quote with no lines', function () {
    [$rfq, $vendors] = issuedRfq();

    expect(fn () => quotes()->record($rfq, $vendors[0], []))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to tabulate fewer than three quotes', function () {
    // THE CONTROL, on responses rather than invitations. Inviting three and
    // receiving one is not a canvass — a comparison of one is a decision that
    // was already made.
    [$rfq, $vendors] = issuedRfq();

    quotes()->record($rfq, $vendors[0], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '100.0000'],
    ]);
    quotes()->record($rfq, $vendors[1], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '110.0000'],
    ]);

    expect(fn () => tabulations()->tabulate($rfq->fresh()))
        ->toThrow(InsufficientQuotesException::class);
});

it('tabulates three quotes and recommends the lowest', function () {
    [$rfq, $vendors] = issuedRfq();

    quotes()->record($rfq, $vendors[0], [['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '130000.0000']]);
    quotes()->record($rfq, $vendors[1], [['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '124500.0000']]);
    quotes()->record($rfq, $vendors[2], [['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '131750.0000']]);

    $tabulation = tabulations()->tabulate($rfq->fresh());

    expect($tabulation->number)->toStartWith('ABC-2026-')
        ->and($tabulation->recommended_vendor_id)->toBe($vendors[1]->getKey())
        ->and($tabulation->quotes_compared)->toBe(3);

    expectMoney($tabulation->fresh()->recommended_amount);
    expect($tabulation->fresh()->recommended_amount)->toBe('124500.0000');
});

it('records how many quotes it compared, so the number cannot be reconstructed later', function () {
    // The count is evidence. Recomputing it from today's rows would answer a
    // different question than "what was on the table when this was decided".
    [$rfq, $vendors] = issuedRfq();

    foreach ($vendors as $index => $vendor) {
        quotes()->record($rfq, $vendor, [
            ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => (string) (100000 + $index * 1000).'.0000'],
        ]);
    }

    $tabulation = tabulations()->tabulate($rfq->fresh());

    expect($tabulation->quotes_compared)->toBe(3);
});

it('tabulates a sole-source RFQ on its single quote', function () {
    // The documented exception, carried through. The three-quote minimum does
    // not apply; the written justification approved one level up does, and that
    // is P1-05.
    $pr = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Approved]);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'), soleSource: true);

    $vendor = Vendor::factory()->create(['code' => 'VEN-ONLY']);
    app(VendorService::class)->accredit($vendor, Carbon::parse('2026-05-01'));
    app(RfqService::class)->invite($rfq, $vendor->fresh());
    app(RfqService::class)->issue($rfq);

    quotes()->record($rfq->fresh(), $vendor->fresh(), [
        ['description' => 'Proprietary valve', 'quantity' => '1.0000', 'unit_price' => '85000.0000'],
    ]);

    $tabulation = tabulations()->tabulate($rfq->fresh());

    expect($tabulation->recommended_vendor_id)->toBe($vendor->getKey())
        ->and($tabulation->quotes_compared)->toBe(1)
        ->and($tabulation->sole_source)->toBeTrue();
});

it('refuses to tabulate the same RFQ twice', function () {
    // A second tabulation would produce a second recommendation for one
    // solicitation, and nothing would say which one the award followed.
    [$rfq, $vendors] = issuedRfq();

    foreach ($vendors as $index => $vendor) {
        quotes()->record($rfq, $vendor, [
            ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => (string) (100000 + $index * 1000).'.0000'],
        ]);
    }

    tabulations()->tabulate($rfq->fresh());

    expect(fn () => tabulations()->tabulate($rfq->fresh()))->toThrow(DomainException::class);
});

it('links the tabulation to its RFQ on the handoff spine', function () {
    [$rfq, $vendors] = issuedRfq();

    foreach ($vendors as $index => $vendor) {
        quotes()->record($rfq, $vendor, [
            ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => (string) (100000 + $index * 1000).'.0000'],
        ]);
    }

    $tabulation = tabulations()->tabulate($rfq->fresh());

    $predecessors = app(DocumentLinker::class)->predecessorsOf($tabulation);

    expect($predecessors)->toHaveCount(1)
        ->and($predecessors->first()->getKey())->toBe($rfq->getKey());
});

it('refuses a quote total changed by a direct update', function () {
    // Gate parity, written alongside the happy path this time rather than
    // waiting for a review to find it. The total is what the award is decided
    // on, so it is derived from the lines and never typed.
    [$rfq, $vendors] = issuedRfq();

    $quote = quotes()->record($rfq, $vendors[0], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '130000.0000'],
    ]);

    expect(fn () => $quote->update(['total_amount' => '1.0000']))
        ->toThrow(DomainException::class);
});

it('refuses a tabulation recommendation changed by a direct update', function () {
    [$rfq, $vendors] = issuedRfq();

    foreach ($vendors as $index => $vendor) {
        quotes()->record($rfq, $vendor, [
            ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => (string) (100000 + $index * 1000).'.0000'],
        ]);
    }

    $tabulation = tabulations()->tabulate($rfq->fresh());

    expect(fn () => $tabulation->update(['recommended_vendor_id' => $vendors[2]->getKey()]))
        ->toThrow(DomainException::class);
});
