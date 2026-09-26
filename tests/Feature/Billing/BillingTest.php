<?php

use App\Domain\Billing\AccomplishmentService;
use App\Domain\Billing\BillingScheduleService;
use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\DeductedLineException;
use App\Domain\Billing\InsufficientAccomplishmentException;
use App\Domain\Billing\MissingMilestoneDocumentsException;
use App\Domain\Contracts\ContractStatus;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Billing, and the approved-or-returned branch — P2-05
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md: "The returned branch is a first-class path, not an error case.
| A returned billing keeps its number, gains a reason, and its deducted lines
| stay blocked from the next submission."
|
| **That last clause is the whole task.** Slide 6 says a returned billing goes
| back to the QS for re-measurement and is resubmitted in the next cutoff "with
| the deduction and its reason logged so the same item is not billed twice" —
| and billing the same item twice is not a hypothetical. It is what happens by
| default: the QS reworks the submission, the deducted line is still in the
| spreadsheet it was copied from, and the client's evaluator is the only control.
|
| So a deduction is not a note on a dead document. It is a BLOCK that survives
| the billing it came from and refuses the line on the next one until somebody
| re-measures it deliberately.
|
| Three other rules sit here, all from slide 6: retention is withheld on every
| billing, a progress milestone cannot bill above what was verified, and the
| billing keeps its number when returned — a returned billing is the same
| document, not a new one.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('submits a downpayment billing with retention withheld', function () {
    // 30% of 48,500,000 is 14,550,000; 10% retention is 1,455,000; the client
    // pays 13,095,000. Retention is withheld on EVERY billing — slide 6 — and
    // it is the money the contractor does not see until the defects liability
    // period ends.
    [$project, $contract, $milestone] = billableDownpayment();

    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment per contract', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    expect($billing->number)->toStartWith('BILL-2026-')
        ->and($billing->status)->toBe(BillingStatus::Submitted)
        ->and((string) $billing->gross_amount)->toBe('14550000.0000')
        ->and((string) $billing->retention_amount)->toBe('1455000.0000')
        ->and((string) $billing->net_amount)->toBe('13095000.0000');
});

it('refuses to submit while the milestone document set is incomplete', function () {
    // F4's gate, reached from the billing chain for the first time.
    $project = Project::factory()->create();
    $contract = Contract::factory()->for($project)->create([
        'status' => ContractStatus::Signed,
        'contract_sum' => '48500000.0000',
    ]);
    $schedule = app(BillingScheduleService::class)->createFor($contract);
    $milestone = $schedule->milestones()->where('code', 'downpayment_30')->sole();

    expect(fn () => billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]))->toThrow(MissingMilestoneDocumentsException::class);
});

it('refuses a progress billing above what the client verified', function () {
    // "No billing without a verified statement of accomplishment" — and the
    // statement has to actually reach the milestone. 42% verified cannot bill a
    // 50% milestone, however complete the paperwork is.
    [$project, $milestone] = billableProgress('42.00');

    expect(fn () => billings()->submit($milestone, [
        ['description' => '50% progress', 'amount' => '9700000.0000', 'line_key' => 'progress_50'],
    ]))->toThrow(InsufficientAccomplishmentException::class);
});

it('submits a progress billing once the accomplishment reaches the threshold', function () {
    [$project, $milestone] = billableProgress('52.00');

    $billing = billings()->submit($milestone, [
        ['description' => '50% progress', 'amount' => '9700000.0000', 'line_key' => 'progress_50'],
    ]);

    expect($billing->status)->toBe(BillingStatus::Submitted);
});

it('ignores an unverified measurement when checking the threshold', function () {
    // The measurement exists and says 52%. Nobody signed it, so the gate must
    // read zero — otherwise "verified" means "typed in".
    $project = Project::factory()->create();
    $contract = Contract::factory()->for($project)->create([
        'status' => ContractStatus::Signed,
        'contract_sum' => '48500000.0000',
    ]);
    $schedule = app(BillingScheduleService::class)->createFor($contract);
    $milestone = $schedule->milestones()->where('code', 'progress_50')->sole();
    $user = User::factory()->create();

    foreach (['accomplishment_report', 'joint_survey', 'photos'] as $key) {
        app(BillingScheduleService::class)->attachDocument($milestone->fresh(), $key, strtoupper($key).'-1', $user);
    }

    app(AccomplishmentService::class)->record($project, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), '52.00', $user);

    expect(fn () => billings()->submit($milestone->fresh(), [
        ['description' => '50% progress', 'amount' => '9700000.0000', 'line_key' => 'progress_50'],
    ]))->toThrow(InsufficientAccomplishmentException::class);
});

it('approves a submitted billing', function () {
    [$project, $contract, $milestone] = billableDownpayment();
    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    $approved = billings()->approve($billing, Carbon::parse('2026-05-20'), 'Approved in full.');

    expect($approved->status)->toBe(BillingStatus::Approved)
        ->and($approved->evaluated_at)->not->toBeNull();
});

it('keeps its number when a billing is returned', function () {
    // A returned billing is the SAME document coming back, not a new one. A
    // fresh number would break the trail the client is following, and make two
    // documents out of one conversation.
    [$project, $contract, $milestone] = billableDownpayment();
    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    $number = $billing->number;

    $returned = billings()->returnForRemeasurement($billing, Carbon::parse('2026-05-20'), 'Quantities disputed at pier 7.', []);

    expect($returned->number)->toBe($number)
        ->and($returned->status)->toBe(BillingStatus::Returned)
        ->and($returned->returned_reason)->toBe('Quantities disputed at pier 7.');
});

it('refuses to return a billing with no reason', function () {
    // The reason is what tells the QS what to re-measure. Without it the
    // returned branch is just a rejection.
    [$project, $contract, $milestone] = billableDownpayment();
    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    expect(fn () => billings()->returnForRemeasurement($billing, Carbon::parse('2026-05-20'), '   ', []))
        ->toThrow(DomainException::class);
});

it('blocks a deducted line from the next submission', function () {
    // THE ONE THAT MATTERS, and the exit gate's own clause. The client deducted
    // the pier-7 line; the QS reworks the billing from the same spreadsheet it
    // came from, and the deducted line is still in it. Nothing but this stops
    // the same item being billed twice.
    [$project, $milestone] = billableProgress('52.00');

    $billing = billings()->submit($milestone, [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
        ['description' => 'Deck slab pour', 'amount' => '9300000.0000', 'line_key' => 'deck-slab'],
    ]);

    billings()->returnForRemeasurement(
        $billing,
        Carbon::parse('2026-05-20'),
        'Pier 7 rebar not yet tied at the time of survey.',
        [['line_key' => 'pier-7-rebar', 'reason' => 'Not tied at survey date.']],
    );

    // Next cutoff. The same line comes back in the resubmission.
    expect(fn () => billings()->submit($milestone->fresh(), [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ]))->toThrow(DeductedLineException::class);
});

it('accepts the resubmission once the deducted line is left out', function () {
    [$project, $milestone] = billableProgress('52.00');

    $billing = billings()->submit($milestone, [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
        ['description' => 'Deck slab pour', 'amount' => '9300000.0000', 'line_key' => 'deck-slab'],
    ]);

    billings()->returnForRemeasurement(
        $billing,
        Carbon::parse('2026-05-20'),
        'Pier 7 rebar not yet tied.',
        [['line_key' => 'pier-7-rebar', 'reason' => 'Not tied at survey date.']],
    );

    $resubmitted = billings()->submit($milestone->fresh(), [
        ['description' => 'Deck slab pour', 'amount' => '9300000.0000', 'line_key' => 'deck-slab'],
    ]);

    expect($resubmitted->status)->toBe(BillingStatus::Submitted)
        ->and((string) $resubmitted->gross_amount)->toBe('9300000.0000');
});

it('releases a blocked line only when it is re-measured deliberately', function () {
    // The block is not permanent — the work does eventually get done. But
    // clearing it is an act with a name and a reason attached, not a side
    // effect of trying again.
    [$project, $milestone] = billableProgress('52.00');

    $billing = billings()->submit($milestone, [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ]);

    billings()->returnForRemeasurement(
        $billing,
        Carbon::parse('2026-05-20'),
        'Not tied.',
        [['line_key' => 'pier-7-rebar', 'reason' => 'Not tied at survey date.']],
    );

    billings()->clearDeduction($project, 'pier-7-rebar', 'Re-measured 2026-06-05, rebar tied and inspected.', User::factory()->create());

    $resubmitted = billings()->submit($milestone->fresh(), [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ]);

    expect($resubmitted->status)->toBe(BillingStatus::Submitted);
});

it('refuses to clear a deduction with no re-measurement note', function () {
    [$project, $milestone] = billableProgress('52.00');

    $billing = billings()->submit($milestone, [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ]);

    billings()->returnForRemeasurement($billing, Carbon::parse('2026-05-20'), 'Not tied.', [
        ['line_key' => 'pier-7-rebar', 'reason' => 'Not tied at survey date.'],
    ]);

    expect(fn () => billings()->clearDeduction($project, 'pier-7-rebar', '  ', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses to approve a billing that was never submitted', function () {
    [$project, $contract, $milestone] = billableDownpayment();
    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    billings()->approve($billing, Carbon::parse('2026-05-20'));

    // Approving twice would raise two invoices against one billing.
    expect(fn () => billings()->approve($billing->fresh(), Carbon::parse('2026-05-21')))
        ->toThrow(DomainException::class);
});

it('records the deduction and its reason against the billing it came from', function () {
    // Slide 6 asks for the deduction AND its reason logged. The reason is what
    // the QS re-measures against, and what stops the same argument being had
    // twice in a month.
    [$project, $milestone] = billableProgress('52.00');

    $billing = billings()->submit($milestone, [
        ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
    ]);

    billings()->returnForRemeasurement($billing, Carbon::parse('2026-05-20'), 'Disputed.', [
        ['line_key' => 'pier-7-rebar', 'reason' => 'Not tied at survey date.'],
    ]);

    $line = $billing->fresh()->lines()->where('line_key', 'pier-7-rebar')->sole();

    expect($line->deducted)->toBeTrue()
        ->and($line->deduction_reason)->toBe('Not tied at survey date.');
});
