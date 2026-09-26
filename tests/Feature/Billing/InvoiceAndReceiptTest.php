<?php

use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Documents\DocumentLinker;
use App\Models\Billing;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Sales invoices and official receipts — P2-06
|--------------------------------------------------------------------------
|
| Slide 6's approved branch, continued: approved → sales invoice → collection
| follow-up → official receipt.
|
| This is where **F9's other half** lands. P0-09 built the withholding shape for
| supplier payments, where the company withholds from the vendor. Here the
| direction reverses: the CLIENT withholds from us, and what arrives in the bank
| is less than the invoice. That difference is not a shortfall to chase — it is
| creditable withholding, and the certificate that proves it is worth exactly the
| amount withheld. A collection recorded without the certificate reference is an
| amount the company has paid in tax and cannot claim back.
|
| The gate is slide 3's step 7 read carefully: an invoice follows an APPROVED
| billing. A returned one has no invoice, and a submitted one has not been
| evaluated yet — neither is something to bill a client against.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('raises an invoice from an approved billing', function () {
    // Gross 14,550,000; retention 10% is 1,455,000; creditable withholding at
    // 2% of gross is 291,000. What the client actually remits is 12,804,000.
    $billing = approvedBilling();

    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    expect($invoice->number)->toStartWith('SI-2026-')
        ->and((string) $invoice->gross_amount)->toBe('14550000.0000')
        ->and((string) $invoice->retention_amount)->toBe('1455000.0000')
        ->and((string) $invoice->withholding_amount)->toBe('291000.0000')
        ->and((string) $invoice->collectible_amount)->toBe('12804000.0000');
});

it('refuses an invoice against a returned billing', function () {
    // THE GATE. A returned billing is one the client disputed. Invoicing it
    // would bill them for work they have just said was not done.
    [$project, $contract, $milestone] = billableDownpayment();

    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    billings()->returnForRemeasurement($billing, Carbon::parse('2026-05-20'), 'Contract copy not attached.', []);

    expect(fn () => collections()->invoice($billing->fresh(), Carbon::parse('2026-05-21'), 'services'))
        ->toThrow(DomainException::class);
});

it('refuses an invoice against a billing the client has not evaluated', function () {
    [$project, $contract, $milestone] = billableDownpayment();

    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    expect($billing->status)->toBe(BillingStatus::Submitted);

    expect(fn () => collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services'))
        ->toThrow(DomainException::class);
});

it('refuses a second invoice for one billing', function () {
    // Two invoices against one billing is the client billed twice for one
    // milestone, and both documents look complete.
    $billing = approvedBilling();
    collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    expect(fn () => collections()->invoice($billing->fresh(), Carbon::parse('2026-05-22'), 'services'))
        ->toThrow(QueryException::class);
});

it('records a collection against the invoice', function () {
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    $receipt = collections()->collect(
        $invoice,
        '12804000.0000',
        Carbon::parse('2026-06-15'),
        'BDO transfer 9911-2',
        '2307-2026-0451',
        User::factory()->create(),
    );

    expect($receipt->number)->toStartWith('OR-2026-')
        ->and(collections()->outstandingFor($invoice->fresh()))->toBe('0.0000')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Collected);
});

it('tracks a partial collection as still outstanding', function () {
    // Partial payment is ordinary, and the AR aging sweep in P2-08 reads this
    // balance. Treating a part payment as settlement is how a receivable
    // disappears from the list that chases it.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    collections()->collect($invoice, '5000000.0000', Carbon::parse('2026-06-15'), 'BDO 1', '2307-1', User::factory()->create());

    expect(collections()->outstandingFor($invoice->fresh()))->toBe('7804000.0000')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PartlyCollected);
});

it('refuses a collection larger than what is outstanding', function () {
    // Over-collection is not generosity, it is a misapplied payment — most
    // often one belonging to a different invoice, and it hides there.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    expect(fn () => collections()->collect($invoice, '13000000.0000', Carbon::parse('2026-06-15'), 'BDO 1', '2307-1'))
        ->toThrow(DomainException::class);
});

it('refuses a collection with no withholding certificate when tax was withheld', function () {
    // F9, stated as a refusal. The client kept 291,000 as tax. Without the
    // certificate reference that 291,000 is money the company has paid and
    // cannot claim back — and it is invisible, because the invoice looks paid.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    expect(fn () => collections()->collect($invoice, '12804000.0000', Carbon::parse('2026-06-15'), 'BDO 1', null))
        ->toThrow(DomainException::class);
});

it('accepts a collection with no certificate when nothing was withheld', function () {
    // The rule is about substantiating tax actually withheld, not paperwork for
    // its own sake. An invoice with no withholding needs no certificate.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), null);

    expect((string) $invoice->withholding_amount)->toBe('0.0000');

    $receipt = collections()->collect($invoice, '13095000.0000', Carbon::parse('2026-06-15'), 'BDO 1', null);

    expect($receipt->number)->toStartWith('OR-2026-');
});

it('refuses a zero or negative collection', function () {
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    expect(fn () => collections()->collect($invoice, '0.0000', Carbon::parse('2026-06-15'), 'BDO 1', '2307-1'))
        ->toThrow(DomainException::class);
});

it('links the invoice to its billing and the receipt to its invoice', function () {
    // The spine, continued into the collection chain. "What was this payment
    // for" has to stay answerable from the receipt backwards.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');
    $receipt = collections()->collect($invoice, '12804000.0000', Carbon::parse('2026-06-15'), 'BDO 1', '2307-1');

    $ancestors = app(DocumentLinker::class)
        ->ancestorsOf($receipt)
        ->map(fn ($model): string => $model::class)
        ->all();

    expect($ancestors)->toContain(SalesInvoice::class)
        ->and($ancestors)->toContain(Billing::class);
});

it('keeps the retention out of what is collectible', function () {
    // Retention is withheld by the client until the defects liability period
    // ends. Invoicing it now would show a receivable nobody intends to pay yet,
    // and the AR aging sweep would chase it every week.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    $collectible = bcsub(
        bcsub((string) $invoice->gross_amount, (string) $invoice->retention_amount, 4),
        (string) $invoice->withholding_amount,
        4,
    );

    expect((string) $invoice->collectible_amount)->toBe($collectible);
});
