<?php

use App\Domain\Projects\ProjectStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The Phase 5 exit gate — P5-11
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Part C, in two sentences:
|
|   "A project reaches close-out and CANNOT BE CLOSED until retention is
|    collected. The close-out report names who cleared each item and when."
|
| Both are behavioural, and the first is a refusal — so the central test walks a
| project all the way from substantial completion to a closed book, and asserts
| the close is refused at the one moment retention is claimed but unpaid. Not
| "unclaimed": that case is obvious. The case worth proving is the one where the
| paperwork looks finished, the client has agreed, the claim is raised, and the
| money has not arrived. That is what "collected" was chosen over "released" for.
|
| The gate does not re-test what the task suites tested. It demonstrates that the
| chain holds end to end — a project walked from certificate to closure through
| the real services, with nothing inserted by hand.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2027-07-01 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('walks a project from substantial completion to a closed book', function () {
    // ---------------------------------------------------------------
    // The whole phase, in order, through the services that own each step.
    // ---------------------------------------------------------------
    $r = closeableProject(collectRetention: false, checklist: false);
    $user = User::factory()->create();

    // Steps 1 to 4 are behind us: certified, punchlisted, back-charged, turned
    // over, billed and collected. What remains is the close itself.
    expect(closeouts()->canClose($r['project']))->toBeFalse();

    // --- Retention: claimed, and NOT yet paid. -----------------------
    $claim = retentionReleases()->claim($r['contract'], '242500.0000', $user);

    // THE GATE. The client has agreed, the claim is raised, the paperwork reads
    // as finished — and the project still cannot close, because the money is
    // not in. This is the sentence the phase was built around.
    expect(fn () => closeouts()->close($r['project']->fresh(), $user))
        ->toThrow(DomainException::class, 'raised but uncollected');

    // --- The money arrives. ------------------------------------------
    retentionReleases()->collect($claim, Carbon::parse('2027-07-15'), 'OR-RET-GATE-1', $user);

    expect(retention()->balanceFor($r['project']->fresh()))->toBe('0.0000');

    // --- The final account, and the checklist signed line by line. ----
    finalAccounts()->file($r['project']->fresh(), $user, 'Final account agreed with the PMO.');

    $checklist = checklists()->open($r['project']->fresh(), $user);

    // Still refused: retention is in, but nobody has signed anything.
    expect(fn () => closeouts()->close($r['project']->fresh(), $user))
        ->toThrow(DomainException::class, 'not cleared line by line');

    foreach ($checklist->items as $item) {
        checklists()->clear($checklist, $item->document_key, $user, 'Checked against the file and signed off.');
    }

    // --- Closed. ------------------------------------------------------
    $closed = closeouts()->close($r['project']->fresh(), $user);

    expect($closed->status)->toBe(ProjectStatus::Closed);
});

it('cannot be closed while retention is held and unclaimed', function () {
    // The plainer half of the same sentence: no claim at all, money still with
    // the client.
    $r = closeableProject(collectRetention: false, checklist: false);
    $user = User::factory()->create();

    finalAccounts()->file($r['project'], $user);
    $checklist = checklists()->open($r['project']->fresh(), $user);

    // Every line the evidence supports — the retention line will refuse, which
    // is the point: the checklist cannot be completed over uncollected money
    // either.
    expect(fn () => checklists()->clear($checklist, 'retention_collected', $user, 'Collected.'))
        ->toThrow(DomainException::class, 'still held by the client');

    expect(closeouts()->canClose($r['project']->fresh()))->toBeFalse();
});

it('names who cleared each item and when', function () {
    // The gate's second sentence, asserted as the report rather than as a
    // property of the rows.
    $r = closeableProject(checklist: false);
    $qs = User::factory()->create(['name' => 'M. Dela Cruz']);
    $accountant = User::factory()->create(['name' => 'R. Villanueva']);

    finalAccounts()->file($r['project'], $accountant);
    $checklist = checklists()->open($r['project']->fresh(), $accountant);

    checklists()->clear($checklist, 'turnover_accepted', $qs, 'Pack accepted by A. Reyes.');
    checklists()->clear($checklist, 'retention_collected', $accountant, 'OR-RET-1 receipted; ledger nil.');

    $report = collect(checklists()->report($checklist->fresh()))->keyBy('key');

    expect($report['turnover_accepted']['cleared_by'])->toBe('M. Dela Cruz')
        ->and($report['turnover_accepted']['cleared_at'])->toStartWith('2027-07-01')
        ->and($report['retention_collected']['cleared_by'])->toBe('R. Villanueva')
        ->and($report['retention_collected']['note'])->toBe('OR-RET-1 receipted; ledger nil.')
        // And the lines nobody has reached are visibly nobody's yet.
        ->and($report['demobilization_complete']['cleared_by'])->toBeNull();
});

it('names every line of the report, across all three panels', function () {
    // Slide 9's three panels, so the report is not eleven rows nobody owns.
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    $panels = collect(checklists()->report($checklist))->pluck('panel')->unique()->values();

    expect($panels->all())->toBe(['documents', 'financial', 'people_and_assets']);
});

it('keeps the close-out report readable after the project is closed', function () {
    // A report that only exists while the project is open is not a record. The
    // gate asks who cleared each item — asked, in practice, months later.
    $r = closeableProject();
    $user = User::factory()->create();

    closeouts()->close($r['project'], $user);

    $checklist = checklists()->forProject($r['project']->fresh());
    $report = collect(checklists()->report($checklist));

    expect($report)->toHaveCount(11)
        ->and($report->whereNull('cleared_by'))->toBeEmpty()
        ->and($report->whereNull('cleared_at'))->toBeEmpty();
});

it('leaves the ledger able to say what the project made', function () {
    // The four chains all reach the ledger, and the final account is summed
    // from it. A close-out that lost that would close the books on a project
    // whose P&L nobody can reproduce.
    $r = closeableProject();

    $account = finalAccounts()->forProject($r['project']);
    $lifetime = finalAccounts()->profitAndLoss($r['project']);

    expect($account)->not->toBeNull()
        ->and($account->revenue)->toBe($lifetime['revenue'])
        ->and($account->gross_profit)->toBe($lifetime['gross_profit']);
});

it('refuses to reopen a closed project by closing it again', function () {
    $r = closeableProject();
    closeouts()->close($r['project'], User::factory()->create());

    expect(fn () => closeouts()->close($r['project']->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, 'already closed');
});
