<?php

use App\Domain\Closeout\PunchlistResponsibility;
use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectStatus;
use App\Models\Project;
use App\Models\Punchlist;
use App\Models\PunchlistItem;
use App\Models\SubstantialCompletion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Substantial completion and the punchlist — P5-01
|--------------------------------------------------------------------------
|
| Slide 9's first two steps, and the one sentence that shapes the whole phase:
|
|   "Close-out is a checklist with NAMED CLEARERS PER LINE, not a status flag."
|
| That is a schema instruction, not a UI preference. A punchlist item therefore
| has no status column at all — `cleared_at` is the truth, and the name beside it
| is what the close-out report is made of. A boolean `is_cleared` would be a
| second place for the same fact to live, and the one that survives an import.
|
| The pairing with step 2 is also structural. Slide 9 computes subcontractor
| back-charges AT punchlist clearing and applies them at final billing, which
| makes an item attributed to a subcontractor the thing F6 charges against. So
| the subcontract is named on the item here, in the migration that creates it —
| not bolted on in P5-02, by which time rows exist that never named anybody.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| Step 1 — substantial completion
|--------------------------------------------------------------------------
*/

it('certifies substantial completion and moves the project to post-construction', function () {
    $project = constructionProject();
    $pm = User::factory()->create();

    $certificate = substantialCompletion()->certify(
        $project,
        Carbon::parse('2026-05-18'),
        $pm,
        clientRepresentative: 'A. Reyes, Project Director',
        remarks: 'Works usable for their intended purpose; minor defects listed.',
    );

    expect($certificate->number)->toStartWith('SCC-2026-')
        ->and($certificate->certified_on->toDateString())->toBe('2026-05-18')
        ->and((int) $certificate->certified_by_user_id)->toBe($pm->getKey())
        ->and($project->fresh()->phase)->toBe(ProjectPhase::PostConstruction);
});

it('refuses a second certificate for one project, at the database', function () {
    // Substantial completion happens once. A second certificate would give the
    // defects liability period two start dates, and retention two release dates.
    $project = constructionProject();

    substantialCompletion()->certify($project, Carbon::parse('2026-05-18'), User::factory()->create());

    expect(fn () => SubstantialCompletion::query()->create([
        'project_id' => $project->getKey(),
        'number' => 'SCC-2026-99999',
        'certified_on' => '2026-05-19',
        'certified_by_user_id' => User::factory()->create()->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses to certify a project that never reached construction', function () {
    // Certifying the completion of works that never started. The phase is not
    // decoration — PLAN.md §1 runs every chain through all four.
    $project = Project::factory()->create(['phase' => ProjectPhase::PreConstruction]);

    expect(fn () => substantialCompletion()->certify($project, Carbon::parse('2026-05-18'), User::factory()->create()))
        ->toThrow(DomainException::class, 'has not reached construction');
});

it('refuses to certify a closed project', function () {
    $project = constructionProject();
    $project->update(['status' => ProjectStatus::Closed]);

    expect(fn () => substantialCompletion()->certify($project, Carbon::parse('2026-05-18'), User::factory()->create()))
        ->toThrow(DomainException::class, 'already closed');
});

it('refuses a completion date in the future', function () {
    // A certificate dated forward is a promise, and everything downstream — the
    // punchlist, the DLP, the retention release — would date from it.
    expect(fn () => substantialCompletion()->certify(constructionProject(), Carbon::parse('2026-06-30'), User::factory()->create()))
        ->toThrow(DomainException::class, 'has not happened yet');
});

/*
|--------------------------------------------------------------------------
| Step 2 — the punchlist
|--------------------------------------------------------------------------
*/

it('issues a punchlist against the certificate', function () {
    [, $certificate] = completedProject();

    $punchlist = punchlists()->issue($certificate, User::factory()->create(), Carbon::parse('2026-05-20'));

    expect($punchlist->number)->toStartWith('PL-2026-')
        ->and((int) $punchlist->substantial_completion_id)->toBe($certificate->getKey())
        ->and($punchlist->closed_at)->toBeNull();
});

it('refuses a punchlist with no substantial completion, at the database', function () {
    // Slide 9 orders step 1 before step 2, and a non-nullable foreign key is the
    // only form of that ordering an importer cannot walk around.
    [$project] = completedProject();

    expect(fn () => Punchlist::query()->create([
        'substantial_completion_id' => null,
        'project_id' => $project->getKey(),
        'number' => 'PL-2026-99999',
        'issued_on' => '2026-05-20',
        'issued_by_user_id' => User::factory()->create()->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses a second punchlist on one certificate', function () {
    [, $certificate] = completedProject();
    punchlists()->issue($certificate, User::factory()->create());

    expect(fn () => punchlists()->issue($certificate, User::factory()->create()))
        ->toThrow(DomainException::class, 'already has a punchlist');
});

it('numbers items within the punchlist and leaves them open', function () {
    $punchlist = issuedPunchlist();

    $first = punchlists()->addItem($punchlist, 'Chipped tiles, lobby', location: 'Ground floor lobby');
    $second = punchlists()->addItem($punchlist, 'Door closer not fitted', location: 'Unit 2A');

    expect($first->item_no)->toBe(1)
        ->and($second->item_no)->toBe(2)
        ->and($first->cleared_at)->toBeNull()
        ->and(punchlists()->openItems($punchlist)->pluck('item_no')->all())->toBe([1, 2]);
});

it('refuses a subcontractor item that names no subcontract', function () {
    // F6's hook. A back-charge computed at clearing needs to know WHOSE payable
    // it reduces; an item blamed on "the subcontractor" in prose charges nobody.
    $punchlist = issuedPunchlist();

    expect(fn () => punchlists()->addItem(
        $punchlist,
        'Steel handrail out of plumb',
        responsibility: PunchlistResponsibility::Subcontractor,
    ))->toThrow(DomainException::class, 'name the subcontract');
});

it('refuses a subcontract belonging to another project', function () {
    // Cross-project attribution back-charges a subcontractor for defects on
    // works they were never let, and every row still looks well-formed.
    $punchlist = issuedPunchlist();
    $elsewhere = subcontractOn(Project::factory()->create());

    expect(fn () => punchlists()->addItem(
        $punchlist,
        'Steel handrail out of plumb',
        responsibility: PunchlistResponsibility::Subcontractor,
        subcontract: $elsewhere,
    ))->toThrow(DomainException::class, 'another project');
});

/*
|--------------------------------------------------------------------------
| Per-item clearing — the obligation itself
|--------------------------------------------------------------------------
*/

it('clears an item with a named clearer, a time and a note', function () {
    $punchlist = issuedPunchlist();
    $item = punchlists()->addItem($punchlist, 'Chipped tiles, lobby');
    $qc = User::factory()->create();

    $cleared = punchlists()->clear($item, $qc, 'Tiles replaced and grouted; re-inspected 21 May.');

    expect($cleared->cleared_at)->not->toBeNull();
    expect((int) $cleared->cleared_by_user_id)->toBe($qc->getKey())
        ->and($cleared->clearance_note)->toContain('re-inspected')
        ->and(punchlists()->openItems($punchlist))->toBeEmpty();
});

it('refuses to clear an item without a note', function () {
    $punchlist = issuedPunchlist();
    $item = punchlists()->addItem($punchlist, 'Chipped tiles, lobby');

    expect(fn () => punchlists()->clear($item, User::factory()->create(), '   '))
        ->toThrow(DomainException::class, 'what was done');
});

it('refuses to clear an item twice', function () {
    // Clearing is not editing. The second signature would overwrite the first,
    // and the close-out report would name the wrong person.
    $punchlist = issuedPunchlist();
    $item = punchlists()->addItem($punchlist, 'Chipped tiles, lobby');
    punchlists()->clear($item, User::factory()->create(), 'Replaced.');

    expect(fn () => punchlists()->clear($item->fresh(), User::factory()->create(), 'Replaced again.'))
        ->toThrow(DomainException::class, 'already cleared');
});

it('refuses a cleared row with no clearer, at the database', function () {
    // The slide 9 obligation, as a CHECK constraint. A control that lives only
    // in a service is not a control — PLAN.md §5.
    $punchlist = issuedPunchlist();
    $item = punchlists()->addItem($punchlist, 'Chipped tiles, lobby');

    expect(fn () => PunchlistItem::query()->whereKey($item->getKey())->update([
        'cleared_at' => now(),
        'cleared_by_user_id' => null,
        'clearance_note' => 'Cleared by nobody in particular.',
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Closing the punchlist
|--------------------------------------------------------------------------
*/

it('refuses to close a punchlist while an item is open', function () {
    $punchlist = issuedPunchlist();
    $done = punchlists()->addItem($punchlist, 'Chipped tiles, lobby');
    punchlists()->addItem($punchlist, 'Door closer not fitted');
    punchlists()->clear($done, User::factory()->create(), 'Replaced.');

    expect(fn () => punchlists()->close($punchlist->fresh(), User::factory()->create(), 'All done.'))
        ->toThrow(DomainException::class, '1 open item');
});

it('closes a punchlist that has been cleared line by line, and names who closed it', function () {
    $punchlist = issuedPunchlist();
    $qc = User::factory()->create();

    foreach (['Chipped tiles, lobby', 'Door closer not fitted'] as $defect) {
        punchlists()->clear(punchlists()->addItem($punchlist, $defect), $qc, 'Made good and re-inspected.');
    }

    $closed = punchlists()->close($punchlist->fresh(), $qc, 'Site walked with the client; no outstanding defects.');

    expect($closed->closed_at)->not->toBeNull();
    expect((int) $closed->closed_by_user_id)->toBe($qc->getKey())
        ->and(punchlists()->isCleared($closed))->toBeTrue();
});

it('does not treat an empty punchlist as cleared until somebody signs it off', function () {
    // The absence-is-not-permission rule, in the place it is easiest to get
    // wrong: "no open items" is true of a punchlist nobody has walked yet.
    $punchlist = issuedPunchlist();

    expect(punchlists()->openItems($punchlist))->toBeEmpty()
        ->and(punchlists()->isCleared($punchlist))->toBeFalse();
});

it('refuses to close a punchlist twice', function () {
    $punchlist = issuedPunchlist();
    punchlists()->close($punchlist, User::factory()->create(), 'Nothing found.');

    expect(fn () => punchlists()->close($punchlist->fresh(), User::factory()->create(), 'Nothing found again.'))
        ->toThrow(DomainException::class, 'already closed');
});

it('reports each cleared item with the name and the time, which is what the close-out report is made of', function () {
    $punchlist = issuedPunchlist();
    $qc = User::factory()->create(['name' => 'M. Santos']);

    punchlists()->clear(punchlists()->addItem($punchlist, 'Chipped tiles, lobby'), $qc, 'Replaced.');

    $report = punchlists()->clearanceReport($punchlist->fresh());

    expect($report)->toHaveCount(1)
        ->and($report[0]['item_no'])->toBe(1)
        ->and($report[0]['cleared_by'])->toBe('M. Santos')
        ->and($report[0]['cleared_at'])->toBe('2026-05-20 09:00:00')
        ->and($report[0]['note'])->toBe('Replaced.');
});
