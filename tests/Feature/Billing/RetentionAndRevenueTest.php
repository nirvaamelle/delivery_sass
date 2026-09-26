<?php

use App\Domain\Billing\RetentionEntryType;
use App\Domain\Cutoffs\CutoffClosedException;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\LedgerCategory;
use App\Models\CutoffCalendar;
use App\Models\ProjectCostLedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Retention ledger and revenue posting — P2-07 and P2-09
|--------------------------------------------------------------------------
|
| Two halves of the same sentence in slide 6: "Retention of 10% is withheld on
| every billing, released after the defects liability period."
|
| **Retention is a ledger, not a column.** The same reasoning as the stock card
| and `project_cost_ledger`: what a contractor actually asks is "how much of our
| money is the client still holding, and from which billings" — and only
| movements answer that. A single `retention_held` figure on the contract can be
| made to agree with itself while agreeing with nothing else.
|
| **And it is released on a date, not on a decision.** The defects liability
| period is contractual; releasing early is releasing money the client has not
| agreed to release, and the request will be refused after somebody has already
| counted it.
|
| P2-09 is the other clause of the phase exit gate: revenue reaches
| `project_cost_ledger` through the P0-13 posting service, the same way material
| cost did in P1-15. Revenue is recognised at the INVOICE — the client has
| accepted the billing and been asked to pay — and at the gross, because
| retention is money earned and withheld, not money unearned.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');

    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Billing,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'cutoff_at' => '2026-09-30 17:00:00',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('withholds retention onto the ledger when a billing is invoiced', function () {
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    $entry = retention()->withhold($invoice);

    expect($entry->type)->toBe(RetentionEntryType::Withheld)
        ->and((string) $entry->amount)->toBe('1455000.0000')
        ->and(retention()->balanceFor($billing->project()->sole()))->toBe('1455000.0000');
});

it('refuses to withhold retention twice for one invoice', function () {
    // Two entries for one billing doubles what the company believes the client
    // is holding, and the error surfaces at close-out as a receivable that does
    // not exist.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    retention()->withhold($invoice);

    expect(fn () => retention()->withhold($invoice->fresh()))
        ->toThrow(DomainException::class);
});

it('refuses to release retention before the defects liability period ends', function () {
    // THE CONTROL. The period is contractual. Releasing early is asking for
    // money the client has not agreed to release — and the request is refused
    // after somebody has already counted it as collectible.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');
    retention()->withhold($invoice);

    $contract = $billing->contract()->sole();
    $contract->update([
        'completed_on' => '2026-06-01',
        'defects_liability_days' => 365,
    ]);

    expect(fn () => retention()->release($contract->fresh(), '1455000.0000', 'RL-1', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses to release retention on a contract that was never completed', function () {
    // No completion date means the defects liability period has not started, so
    // it certainly has not ended. Treating a missing date as permission is the
    // same mistake as treating a missing cutoff calendar as an open period.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');
    retention()->withhold($invoice);

    expect(fn () => retention()->release($billing->contract()->sole(), '1455000.0000', 'RL-1'))
        ->toThrow(DomainException::class);
});

it('releases retention once the defects liability period has run', function () {
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');
    retention()->withhold($invoice);

    $contract = $billing->contract()->sole();
    $contract->update(['completed_on' => '2025-01-01', 'defects_liability_days' => 365]);

    $entry = retention()->release($contract->fresh(), '1455000.0000', 'RL-2026-0001', User::factory()->create());

    expect($entry->type)->toBe(RetentionEntryType::Released)
        ->and(retention()->balanceFor($billing->project()->sole()))->toBe('0.0000');
});

it('refuses to release more retention than is held', function () {
    // Releasing more than was withheld is inventing money, and it nets against
    // a balance the client's own records will not match.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');
    retention()->withhold($invoice);

    $contract = $billing->contract()->sole();
    $contract->update(['completed_on' => '2025-01-01', 'defects_liability_days' => 365]);

    expect(fn () => retention()->release($contract->fresh(), '2000000.0000', 'RL-1'))
        ->toThrow(DomainException::class);
});

it('refuses a release with no reference', function () {
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');
    retention()->withhold($invoice);

    $contract = $billing->contract()->sole();
    $contract->update(['completed_on' => '2025-01-01', 'defects_liability_days' => 365]);

    expect(fn () => retention()->release($contract->fresh(), '1455000.0000', '   '))
        ->toThrow(DomainException::class);
});

it('keeps the balance as a sum of movements rather than a stored figure', function () {
    // Partial release is ordinary. The balance has to be able to express it, and
    // a stored figure that anything can write is one nobody can explain.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');
    retention()->withhold($invoice);

    $contract = $billing->contract()->sole();
    $contract->update(['completed_on' => '2025-01-01', 'defects_liability_days' => 365]);

    retention()->release($contract->fresh(), '455000.0000', 'RL-1');

    expect(retention()->balanceFor($billing->project()->sole()))->toBe('1000000.0000')
        ->and(retention()->entriesFor($billing->project()->sole())->count())->toBe(2);
});

it('posts revenue to the ledger when the invoice is raised', function () {
    // P2-09, and the phase exit gate's last clause. Revenue at the GROSS:
    // retention is money earned and withheld, not money unearned.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    $entry = revenue()->post($invoice);

    expect($entry->category)->toBe(LedgerCategory::Revenue)
        ->and($entry->amount)->toBe('14550000.0000')
        ->and($entry->document_number)->toBe($invoice->number);
});

it('refuses to post one invoice to the ledger twice', function () {
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    revenue()->post($invoice);

    expect(fn () => revenue()->post($invoice->fresh()))
        ->toThrow(DomainException::class);
});

it('puts the revenue row in the ledger the P and L is assembled from', function () {
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    revenue()->post($invoice);

    expect(ProjectCostLedgerEntry::query()
        ->where('document_number', $invoice->number)
        ->where('category', LedgerCategory::Revenue)
        ->exists())->toBeTrue();
});

it('leaves the invoice unposted when the ledger refuses it', function () {
    // Same discipline as the material posting in P1-15: the stamp is written
    // inside the posting transaction, so a refused posting cannot leave an
    // invoice claiming to be in a ledger that never took it.
    CutoffCalendar::query()->where('cutoff_type', CutoffType::Billing)->update([
        'cutoff_at' => '2026-05-01 17:00:00',
    ]);

    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    try {
        revenue()->post($invoice);
    } catch (CutoffClosedException) {
        // expected
    }

    expect($invoice->fresh()->project_cost_ledger_entry_id)->toBeNull();
});
