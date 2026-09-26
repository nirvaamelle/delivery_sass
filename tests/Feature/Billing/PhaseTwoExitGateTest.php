<?php

use App\Domain\Billing\ArAgingService;
use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\DeductedLineException;
use App\Domain\Billing\RetentionService;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\RevenuePoster;
use App\Models\CutoffCalendar;
use App\Models\ProjectCostLedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Phase 2 exit gate — P2-11
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Part C states it as behaviour:
|
|   "A 30% downpayment billing and one 50% progress billing post; one billing
|    is returned, re-measured, and resubmitted in the next cutoff without the
|    deducted line reappearing. Revenue and receivables appear in the ledger."
|
| The clause that carries the phase is the middle one, and specifically its last
| six words. Returning a billing is easy — any status column can do it. What the
| gate actually asks is that the **deducted line does not come back**, and that
| is a claim about the NEXT submission, which is a different document written by
| a different person in a different month.
|
| So the central test below walks the whole arc: bill, return with a deduction,
| resubmit in the next cutoff, and assert twice — that the deducted line is
| refused if it reappears, and that the resubmission succeeds when it does not.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');

    // Both months the gate spans: the billing is returned in one cutoff and
    // resubmitted in the next, which is the whole point of the clause.
    foreach ([['2026-05-01', '2026-05-31'], ['2026-06-01', '2026-06-30']] as [$start, $end]) {
        CutoffCalendar::query()->create([
            'project_id' => null,
            'cutoff_type' => CutoffType::Billing,
            'period_start' => $start,
            'period_end' => $end,
            'cutoff_at' => '2026-12-31 17:00:00',
        ]);
    }
});

afterEach(fn () => Carbon::setTestNow());

it('posts a 30% downpayment billing', function () {
    // CLAUSE 1. 30% of 48,500,000 is 14,550,000, less 10% retention.
    [$project, $contract, $milestone] = billableDownpayment();

    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment per contract', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    $approved = billings()->approve($billing, Carbon::parse('2026-05-20'));
    $invoice = collections()->invoice($approved->fresh(), Carbon::parse('2026-05-21'), 'services');

    expect($approved->status)->toBe(BillingStatus::Approved)
        ->and((string) $invoice->gross_amount)->toBe('14550000.0000')
        ->and((string) $invoice->retention_amount)->toBe('1455000.0000');
});

it('posts a 50% progress billing against a verified accomplishment', function () {
    // CLAUSE 2, and it carries slide 3's third gate rule with it: no billing
    // without a verified statement of accomplishment.
    [$project, $milestone] = billableProgress('52.00');

    $billing = billings()->submit($milestone, [
        ['description' => '50% progress', 'amount' => '9700000.0000', 'line_key' => 'progress-50'],
    ]);

    $approved = billings()->approve($billing, Carbon::parse('2026-05-25'));
    $invoice = collections()->invoice($approved->fresh(), Carbon::parse('2026-05-26'), 'services');

    expect($invoice->number)->toStartWith('SI-2026-');
});

it('returns a billing, re-measures it, and resubmits it in the next cutoff without the deducted line', function () {
    // CLAUSE 3 — THE ONE THE PHASE IS ABOUT, and the full arc in one test.
    //
    // Returning a billing is easy; any status column does it. What the gate asks
    // is that the deducted line does not come back, and that is a claim about a
    // document written next month by somebody working from the same spreadsheet.
    [$project, $milestone] = billableProgress('52.00');

    // May: the billing goes in with two lines.
    $may = billings()->submit($milestone, [
        ['description' => 'Deck slab pour, grid 4-9', 'amount' => '9300000.0000', 'line_key' => 'deck-slab'],
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ], null, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));

    $number = $may->number;

    // The client deducts the pier-7 line and sends the billing back.
    billings()->returnForRemeasurement(
        $may,
        Carbon::parse('2026-05-28'),
        'Pier 7 rebar not tied at the date of the joint survey.',
        [['line_key' => 'pier-7-rebar', 'reason' => 'Not tied at survey date.']],
    );

    // The billing kept its number: it is the same document coming back.
    expect($may->fresh()->number)->toBe($number)
        ->and($may->fresh()->status)->toBe(BillingStatus::Returned);

    // June. The QS reworks the submission — from the spreadsheet the deducted
    // line is still in.
    Carbon::setTestNow('2026-06-10 09:00:00');

    expect(fn () => billings()->submit($milestone->fresh(), [
        ['description' => 'Deck slab pour, grid 4-9', 'amount' => '9300000.0000', 'line_key' => 'deck-slab'],
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ]))->toThrow(DeductedLineException::class);

    // With the deducted line left out, the resubmission goes through.
    $june = billings()->submit($milestone->fresh(), [
        ['description' => 'Deck slab pour, grid 4-9', 'amount' => '9300000.0000', 'line_key' => 'deck-slab'],
    ], null, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));

    expect($june->status)->toBe(BillingStatus::Submitted)
        ->and((string) $june->gross_amount)->toBe('9300000.0000')
        ->and($june->number)->not->toBe($number);

    // And the reason is still readable on the billing it came from, which is
    // what the QS re-measures against.
    expect($may->fresh()->lines()->where('line_key', 'pier-7-rebar')->sole()->deduction_reason)
        ->toBe('Not tied at survey date.');
});

it('lets a re-measured line back in once somebody clears it deliberately', function () {
    // The block is not permanent — the rebar does eventually get tied. But
    // clearing it is an act with a name and a note on it, which is what
    // distinguishes a re-measurement from simply trying again.
    [$project, $milestone] = billableProgress('52.00');

    $may = billings()->submit($milestone, [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ]);

    billings()->returnForRemeasurement($may, Carbon::parse('2026-05-28'), 'Not tied.', [
        ['line_key' => 'pier-7-rebar', 'reason' => 'Not tied at survey date.'],
    ]);

    billings()->clearDeduction($project, 'pier-7-rebar', 'Re-measured 2026-06-05; rebar tied and inspected.', User::factory()->create());

    $june = billings()->submit($milestone->fresh(), [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ]);

    expect($june->status)->toBe(BillingStatus::Submitted);
});

it('puts revenue in the ledger', function () {
    // CLAUSE 4, first half. PLAN.md §1's organising principle, reached by the
    // billing chain: every process ends in project_cost_ledger.
    [$project, $contract, $milestone] = billableDownpayment();

    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    $invoice = collections()->invoice(
        billings()->approve($billing, Carbon::parse('2026-05-20'))->fresh(),
        Carbon::parse('2026-05-21'),
        'services',
    );

    $entry = app(RevenuePoster::class)->post($invoice);

    expect($entry->category)->toBe(LedgerCategory::Revenue)
        ->and($entry->amount)->toBe('14550000.0000')
        ->and(ProjectCostLedgerEntry::query()
            ->where('document_number', $invoice->number)
            ->where('category', LedgerCategory::Revenue)
            ->exists())->toBeTrue();
});

it('puts the receivable and its retention where they can be read', function () {
    // CLAUSE 4, second half. "Receivables appear" means the two figures a
    // finance manager actually needs: what the client still owes, and what they
    // are holding back.
    [$project, $contract, $milestone] = billableDownpayment();

    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    $invoice = collections()->invoice(
        billings()->approve($billing, Carbon::parse('2026-05-20'))->fresh(),
        Carbon::parse('2026-05-21'),
        'services',
    );

    app(RetentionService::class)->withhold($invoice);

    // Gross 14,550,000 less 1,455,000 retention less 291,000 withheld at source.
    expect(collections()->outstandingFor($invoice->fresh()))->toBe('12804000.0000')
        ->and(app(RetentionService::class)->balanceFor($project))->toBe('1455000.0000');

    // A part payment moves the receivable and leaves the rest outstanding.
    collections()->collect($invoice->fresh(), '8000000.0000', Carbon::parse('2026-06-01'), 'BDO 1', '2307-1');

    expect(collections()->outstandingFor($invoice->fresh()))->toBe('4804000.0000');
});

it('escalates the unpaid receivable to the project manager at thirty days', function () {
    // Not one of the four clauses, and included deliberately. The gate could be
    // satisfied by a system that raises receivables nobody ever chases, and F13
    // is the finding that says chasing them is a named, scheduled action.
    [$project, $contract, $milestone] = billableDownpayment();

    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    collections()->invoice(
        billings()->approve($billing, Carbon::parse('2026-05-20'))->fresh(),
        now()->copy()->subDays(31),
        'services',
    );

    $escalations = app(ArAgingService::class)->sweep(now(), $project);

    expect($escalations)->toHaveCount(1)
        ->and($escalations->first()->addressed_to_role)->toBe('project-manager');
});

it('refuses to invoice a billing the client returned', function () {
    // Also beyond the four clauses. A gate that only demonstrates the happy path
    // demonstrates nothing: the returned branch has to actually stop the money.
    [$project, $contract, $milestone] = billableDownpayment();

    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    billings()->returnForRemeasurement($billing, Carbon::parse('2026-05-20'), 'Contract copy not attached.', []);

    expect(fn () => collections()->invoice($billing->fresh(), Carbon::parse('2026-05-21'), 'services'))
        ->toThrow(DomainException::class);
});
