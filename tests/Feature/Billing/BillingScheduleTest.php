<?php

use App\Domain\Billing\MissingMilestoneDocumentsException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Billing schedule and milestone documents — P2-02 and P2-03, closing F4
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md: "Each milestone requires a DIFFERENT document set, exactly as
| each approval tier does. PLAN.md has `billing_milestones` but nothing storing
| what must be attached before submission opens. Without it, 'no billing without
| a verified statement of accomplishment' is the only gate enforced and the other
| four document requirements are honour-system."
|
| So the finding is not that documents are missing from the deck — the deck lists
| them per milestone, precisely. The finding is that nothing in the system knows
| what they are, which makes four of the five milestones unenforced.
|
| Two design consequences, both tested below:
|
|   - the sets are **per milestone**, so satisfying the 30% downpayment's three
|     documents says nothing about the 50% progress billing's three;
|   - they come from **configuration keyed by contract type**, because Part D
|     item 2 may change the milestones themselves and the client's answer should
|     be an edit rather than a deployment.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('builds the five milestones from the contract type', function () {
    $schedule = schedules()->createFor(signedContract());

    expect($schedule->milestones()->count())->toBe(5)
        ->and($schedule->milestones()->orderBy('sequence')->first()->code)->toBe('downpayment_30');
});

it('gives each milestone its own document set', function () {
    // THE FINDING, stated as an assertion. The downpayment wants a signed
    // contract; the 50% progress billing wants a joint survey. A single shared
    // list would satisfy both with whichever documents happened to be on file.
    $schedule = schedules()->createFor(signedContract());

    $downpayment = $schedule->milestones()->where('code', 'downpayment_30')->sole();
    $progress = $schedule->milestones()->where('code', 'progress_50')->sole();

    $downpaymentKeys = $downpayment->requirements()->pluck('document_key')->sort()->values()->all();
    $progressKeys = $progress->requirements()->pluck('document_key')->sort()->values()->all();

    expect($downpaymentKeys)->toContain('signed_contract')
        ->and($progressKeys)->toContain('joint_survey')
        ->and($downpaymentKeys)->not->toEqual($progressKeys);
});

it('refuses to open submission while a required document is missing', function () {
    // F4's whole point. Without this the other four milestones are honour
    // system, and a billing goes to the client missing the evidence it is
    // supposed to rest on.
    $schedule = schedules()->createFor(signedContract());
    $milestone = $schedule->milestones()->where('code', 'downpayment_30')->sole();

    expect(fn () => schedules()->assertSubmissionOpen($milestone))
        ->toThrow(MissingMilestoneDocumentsException::class);
});

it('names every missing document, not just the first', function () {
    // A QS who clears one blocker only to be shown the next, one round trip at
    // a time, is how a monthly cutoff gets missed.
    $schedule = schedules()->createFor(signedContract());
    $milestone = $schedule->milestones()->where('code', 'downpayment_30')->sole();

    expect(schedules()->missingFor($milestone))->toHaveCount(3);

    schedules()->attachDocument($milestone, 'billing_form', 'BF-2026-001', User::factory()->create());

    expect(schedules()->missingFor($milestone->fresh()))->toHaveCount(2);
});

it('opens submission once every required document is attached', function () {
    $schedule = schedules()->createFor(signedContract());
    $milestone = $schedule->milestones()->where('code', 'downpayment_30')->sole();
    $user = User::factory()->create();

    foreach (['billing_form', 'signed_contract', 'invoice_request'] as $key) {
        schedules()->attachDocument($milestone->fresh(), $key, strtoupper($key).'-1', $user);
    }

    expect(schedules()->missingFor($milestone->fresh()))->toBe([])
        ->and(schedules()->isSubmissionOpen($milestone->fresh()))->toBeTrue();
});

it('does not block submission on an optional document', function () {
    // The distinction has to be real, or "required" means nothing. The final
    // milestone's turnover photographs are nice to have; its clearances are not.
    $schedule = schedules()->createFor(signedContract());
    $milestone = $schedule->milestones()->where('code', 'final_100')->sole();
    $user = User::factory()->create();

    foreach (['certificate_of_completion', 'warranty', 'clearances'] as $key) {
        schedules()->attachDocument($milestone->fresh(), $key, strtoupper($key).'-1', $user);
    }

    expect(schedules()->isSubmissionOpen($milestone->fresh()))->toBeTrue();
});

it('refuses a document the milestone never asked for', function () {
    // Otherwise the requirement is satisfiable by attaching anything at all,
    // and the count passes while the evidence is absent.
    $schedule = schedules()->createFor(signedContract());
    $milestone = $schedule->milestones()->where('code', 'downpayment_30')->sole();

    expect(fn () => schedules()->attachDocument($milestone, 'holiday_photos', 'X-1', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses a second schedule for one contract', function () {
    // Two schedules is two sets of milestones against one contract sum, and the
    // percentages would total 200.
    $contract = signedContract();
    schedules()->createFor($contract);

    expect(fn () => schedules()->createFor($contract->fresh()))
        ->toThrow(QueryException::class);
});

it('refuses a contract type whose milestones do not total one hundred percent', function () {
    // A schedule that does not total 100 is a contract under- or over-billed by
    // construction, and nobody finds out until the final billing fails to
    // balance — by which time the work is done.
    config()->set('billing.contract_types.broken', [
        [
            'code' => 'half',
            'name' => 'Half',
            'percentage' => '50.00',
            'trigger' => 'Something',
            'accomplishment_threshold' => null,
            'documents' => [],
        ],
    ]);

    expect(fn () => schedules()->createFor(signedContract(), 'broken'))
        ->toThrow(DomainException::class);
});

it('refuses an unknown contract type rather than falling back to the default', function () {
    // Falling back would build slide 6's five milestones for a contract that
    // bills on some other schedule entirely, and it would look correct.
    expect(fn () => schedules()->createFor(signedContract(), 'cost_plus'))
        ->toThrow(DomainException::class);
});

it('carries the accomplishment threshold each progress milestone bills against', function () {
    // The number the billing gate in P2-05 reads. Kept on the milestone rather
    // than parsed out of its name, because "50% progress" is a label and a
    // label is not a control.
    $schedule = schedules()->createFor(signedContract());

    $downpayment = $schedule->milestones()->where('code', 'downpayment_30')->sole();
    $progress = $schedule->milestones()->where('code', 'progress_50')->sole();

    expect($downpayment->accomplishment_threshold)->toBeNull()
        ->and((string) $progress->accomplishment_threshold)->toBe('50.00');
});

it('computes what each milestone bills against the contract sum', function () {
    // 30% of 48,500,000 is 14,550,000. Computed from the contract rather than
    // typed onto the milestone, so a variation order that changes the contract
    // sum does not leave five stale amounts behind it.
    $contract = signedContract();
    $schedule = schedules()->createFor($contract);

    $downpayment = $schedule->milestones()->where('code', 'downpayment_30')->sole();

    expect(schedules()->amountFor($downpayment))->toBe('14550000.0000');
});

it('totals exactly the contract sum across every milestone', function () {
    // The arithmetic that matters: five percentages rounded independently must
    // still add up to the contract, or the final billing carries the drift.
    $contract = signedContract();
    $schedule = schedules()->createFor($contract);

    $total = '0.0000';

    foreach ($schedule->milestones()->get() as $milestone) {
        $total = bcadd($total, schedules()->amountFor($milestone), 4);
    }

    expect($total)->toBe('48500000.0000');
});
