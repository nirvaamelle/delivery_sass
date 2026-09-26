<?php

use App\Domain\Billing\AgingBucket;
use App\Domain\Billing\ArAgingService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| AR aging and the 30-day escalation — P2-08, closing F13
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md: "AR escalation to the project manager at 30 days is a specific,
| scheduled action with a named recipient. PLAN.md has an AR aging sweep but not
| the escalation rule."
|
| The distinction the finding draws is the whole task. A report is something
| somebody has to open; an escalation arrives. Slide 6 asks for both — "AR aging
| reviewed weekly; unpaid billings escalated to the project manager at 30 days" —
| and the second is the one that does not get built, because the first looks like
| it covers it.
|
| Two things follow, and both are tested below. The escalation has a **named
| recipient** — the project manager, not "finance" and not a mailing list — and
| it fires **at thirty days**, not at thirty-one and not whenever the sweep
| happens to run. And it must not fire twice for the same invoice, or the inbox
| teaches its reader to ignore it, which is worse than not having built it.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function aging(): ArAgingService
{
    return app(ArAgingService::class);
}

/**
 * An invoice issued `$daysAgo` days ago, with nothing collected against it.
 */
function outstandingInvoice(int $daysAgo)
{
    $billing = approvedBilling();

    return collections()->invoice($billing, now()->copy()->subDays($daysAgo), 'services');
}

it('puts an invoice issued today in the current bucket', function () {
    // Current means not yet aged at all. An invoice issued this morning is not
    // late by any reading, and putting it in the 1-30 column would make the
    // weekly review start every invoice one bucket in.
    $invoice = outstandingInvoice(0);

    expect(aging()->bucketFor($invoice))->toBe(AgingBucket::Current);
});

it('ages an invoice from its first day outstanding', function () {
    $invoice = outstandingInvoice(5);

    expect(aging()->bucketFor($invoice))->toBe(AgingBucket::OneToThirty);
});

it('ages an invoice into the right bucket as it goes unpaid', function () {
    expect(aging()->bucketFor(outstandingInvoice(40)))->toBe(AgingBucket::ThirtyOneToSixty)
        ->and(aging()->bucketFor(outstandingInvoice(75)))->toBe(AgingBucket::SixtyOneToNinety)
        ->and(aging()->bucketFor(outstandingInvoice(120)))->toBe(AgingBucket::OverNinety);
});

it('does not escalate an invoice at twenty-nine days', function () {
    // The boundary, from below. A rule that fires early trains the project
    // manager to ignore it before the real one arrives.
    $invoice = outstandingInvoice(29);

    $escalations = aging()->sweep();

    expect($escalations)->toHaveCount(0);
});

it('escalates an unpaid invoice at exactly thirty days', function () {
    // F13's rule, on its own boundary. Slide 6 says thirty, so thirty is where
    // it fires.
    $invoice = outstandingInvoice(30);

    $escalations = aging()->sweep();

    expect($escalations)->toHaveCount(1)
        ->and($escalations->first()->sales_invoice_id)->toBe($invoice->getKey())
        ->and($escalations->first()->days_outstanding)->toBe(30);
});

it('addresses the escalation to the project manager', function () {
    // The finding's other half: a NAMED recipient. "Escalated" with nobody to
    // escalate to is a status change, not an escalation.
    outstandingInvoice(35);

    $escalation = aging()->sweep()->first();

    expect($escalation->addressed_to_role)->toBe('project-manager');
});

it('does not escalate the same invoice twice', function () {
    // The sweep runs weekly. An escalation that repeats every week until the
    // client pays teaches its reader to ignore the inbox, which is worse than
    // never having built it.
    outstandingInvoice(35);

    aging()->sweep();
    $second = aging()->sweep();

    expect($second)->toHaveCount(0);
});

it('never escalates an invoice that has been collected in full', function () {
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, now()->copy()->subDays(45), 'services');

    collections()->collect($invoice, (string) $invoice->collectible_amount, now(), 'BDO 1', '2307-1', User::factory()->create());

    expect(aging()->sweep())->toHaveCount(0);
});

it('still escalates an invoice that was only partly collected', function () {
    // A part payment is not a settlement. Dropping partly-paid invoices off the
    // sweep is how a receivable quietly stops being chased.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, now()->copy()->subDays(45), 'services');

    collections()->collect($invoice, '1000000.0000', now(), 'BDO 1', '2307-1', User::factory()->create());

    expect(aging()->sweep())->toHaveCount(1);
});

it('ages only what is actually collectible', function () {
    // Retention is not in the aging. The client is holding it by agreement until
    // the defects liability period ends, so chasing it weekly would be chasing
    // money nobody is late paying.
    $invoice = outstandingInvoice(45);

    expect(aging()->outstandingFor($invoice))->toBe((string) $invoice->collectible_amount)
        ->and(aging()->outstandingFor($invoice))->not->toBe((string) $invoice->gross_amount);
});

it('summarises a project into buckets', function () {
    // The weekly review, which is the other half of slide 6's sentence. One
    // number per bucket, so the conversation is about the ninety-day column
    // rather than about a list.
    $billing = approvedBilling();
    $project = $billing->project()->sole();

    collections()->invoice($billing, now()->copy()->subDays(10), 'services');

    $summary = aging()->summaryFor($project);

    expect($summary[AgingBucket::OneToThirty->value])->not->toBe('0.0000')
        ->and($summary[AgingBucket::OverNinety->value])->toBe('0.0000');
});

it('records when an escalation was acknowledged', function () {
    // An escalation nobody can close stays on the screen forever and stops being
    // read. Acknowledging it is what says a human acted.
    outstandingInvoice(35);
    $escalation = aging()->sweep()->first();

    $acknowledged = aging()->acknowledge($escalation, User::factory()->create(), 'Client confirms payment 2026-06-20.');

    expect($acknowledged->acknowledged_at)->not->toBeNull()
        ->and($acknowledged->resolution)->toBe('Client confirms payment 2026-06-20.');
});

it('escalates again if the invoice crosses a further bucket', function () {
    // Sixty days is a different conversation from thirty, and the project
    // manager should hear about it once. This is the one repeat that is not
    // noise.
    $invoice = outstandingInvoice(35);
    aging()->sweep();

    Carbon::setTestNow(now()->copy()->addDays(30));

    expect(aging()->sweep())->toHaveCount(1);
});
