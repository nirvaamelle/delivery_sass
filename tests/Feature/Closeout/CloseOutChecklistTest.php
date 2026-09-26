<?php

use App\Domain\Closeout\CloseOutPanel;
use App\Models\CloseOutChecklistItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The close-out checklist — P5-08
|--------------------------------------------------------------------------
|
| Slide 9's structural obligation, the one PHASE-PLAN.md puts at the head of the
| whole phase: **"Close-out is a checklist with named clearers per line, not a
| status flag."** And the exit gate's second sentence: "the close-out report
| names who cleared each item and when."
|
| The slide's three panels are the template — documents to close, financial
| close, people and assets.
|
| **Every line needs a name and a note. No line has a status column.** That is
| the rule stated as schema: `cleared_at` IS the clearance, the name beside it is
| what the report is made of, and there is nowhere for a boolean to disagree with
| either.
|
| **A line whose fact the build already knows cannot be signed while that fact
| is false.** This is the part worth defending, because it is not what "named
| clearer per line" says on its own. A signature and evidence are stronger than
| either alone: the person still signs — nobody is replaced by a query — but a
| clerk cannot certify "retention collected" over a claim nobody paid. Slide 9's
| rule stops a status flag; this stops a signature that is not true.
|
| Lines whose evidence the build does not yet hold are declared `manual` and get
| slide 9's baseline: a name, a time, a note. P5-09 upgrades two of them.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2027-07-01 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| Opening it
|--------------------------------------------------------------------------
*/

it('opens a checklist with slide 9 three panels, one line per item', function () {
    $r = closeableProject(checklist: false);

    $checklist = checklists()->open($r['project'], User::factory()->create());

    expect($checklist->number)->toStartWith('COC-2027-')
        ->and($checklist->items()->pluck('panel')->unique()->values()->all())->toBe([
            CloseOutPanel::Documents,
            CloseOutPanel::Financial,
            CloseOutPanel::PeopleAndAssets,
        ]);
});

it('refuses a second checklist on one project', function () {
    $r = closeableProject(checklist: false);
    checklists()->open($r['project'], User::factory()->create());

    expect(fn () => checklists()->open($r['project'], User::factory()->create()))
        ->toThrow(DomainException::class, 'already has a close-out checklist');
});

it('opens every line outstanding, with nobody against it', function () {
    $r = closeableProject(checklist: false);

    $checklist = checklists()->open($r['project'], User::factory()->create());

    expect($checklist->items()->whereNotNull('cleared_at')->count())->toBe(0)
        ->and(count(checklists()->outstandingFor($checklist)))
        ->toBe($checklist->items()->count());
});

/*
|--------------------------------------------------------------------------
| Clearing a line
|--------------------------------------------------------------------------
*/

it('clears a line, and names who cleared it and when', function () {
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());
    $accountant = User::factory()->create();

    $item = checklists()->clear($checklist, 'retention_collected', $accountant, 'OR-RET-1 receipted; ledger nil.');

    expect($item->cleared_at)->not->toBeNull()
        ->and((int) $item->cleared_by_user_id)->toBe($accountant->getKey())
        ->and($item->note)->toBe('OR-RET-1 receipted; ledger nil.');
});

it('refuses to clear a line the checklist does not have', function () {
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    expect(fn () => checklists()->clear($checklist, 'invented_line', User::factory()->create(), 'Done.'))
        ->toThrow(DomainException::class, 'does not have a line');
});

it('refuses a clearance with no note', function () {
    // The note is what the report says. A signature against nothing is a tick
    // with a name on it.
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    expect(fn () => checklists()->clear($checklist, 'retention_collected', User::factory()->create(), '  '))
        ->toThrow(DomainException::class, 'note');
});

it('refuses to clear the same line twice', function () {
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());
    checklists()->clear($checklist, 'retention_collected', User::factory()->create(), 'Receipted.');

    expect(fn () => checklists()->clear($checklist->fresh(), 'retention_collected', User::factory()->create(), 'Again.'))
        ->toThrow(DomainException::class, 'already cleared');
});

it('refuses a cleared row with no clearer, at the database', function () {
    // Slide 9's rule as a constraint, not a convention.
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());
    $item = $checklist->items()->where('document_key', 'retention_collected')->sole();

    expect(fn () => CloseOutChecklistItem::query()->whereKey($item->getKey())->update([
        'cleared_at' => now(),
        'cleared_by_user_id' => null,
        'note' => 'Cleared by nobody in particular.',
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| A signature the evidence does not support
|--------------------------------------------------------------------------
*/

it('refuses to certify retention collected while a claim is unpaid', function () {
    // The heart of the task. A person still signs — but not for something that
    // is not true, and the build already knows whether it is.
    $r = closeableProject(collectRetention: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());
    retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());

    expect(fn () => checklists()->clear($checklist, 'retention_collected', User::factory()->create(), 'Collected.'))
        ->toThrow(DomainException::class, 'raised but uncollected');
});

it('refuses to certify the site demobilized while the demobilization is open', function () {
    $r = closeableProject(demobilize: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    expect(fn () => checklists()->clear($checklist, 'demobilization_complete', User::factory()->create(), 'Site clear.'))
        ->toThrow(DomainException::class, 'has not been demobilized');
});

it('refuses to certify final collection while an invoice is outstanding', function () {
    $r = closeableProject(collectInvoice: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    expect(fn () => checklists()->clear($checklist, 'final_billing_collected', User::factory()->create(), 'All in.'))
        ->toThrow(DomainException::class, $r['invoice']->number);
});

it('lets the same line be cleared once the evidence supports it', function () {
    $r = closeableProject(demobilize: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());
    $user = User::factory()->create();

    $demobilization = demobilizations()->open($r['project'], $user);
    demobilizations()->clear($demobilization, $r['employee'], $user, 'Tools returned.');
    demobilizations()->complete($demobilization->fresh(), $user, 'Site handed back.');

    expect(checklists()->clear($checklist, 'demobilization_complete', $user, 'Site clear.')->cleared_at)
        ->not->toBeNull();
});

it('takes a signature on a manual line without asking the build to prove it', function () {
    // Lines whose evidence the build does not hold get slide 9's baseline: a
    // name, a time and a note. Refusing them for want of a query the build
    // cannot run would make the checklist uncompletable.
    //
    // The archive line is the one that stays manual. `final_project_pl` was
    // manual when this suite was written and P5-09 upgraded it, which is the
    // seam working: nothing in the build knows whether the as-built drawings
    // actually reached the archive, and nothing pretends to.
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    expect(checklists()->clear($checklist, 'as_built_records_archived', User::factory()->create(), 'Lodged with the PMO pack.')->cleared_at)
        ->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The report, and closing on it
|--------------------------------------------------------------------------
*/

it('names who cleared each item and when', function () {
    // The exit gate's second sentence, verbatim.
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());
    $accountant = User::factory()->create();
    checklists()->clear($checklist, 'retention_collected', $accountant, 'OR-RET-1 receipted.');

    $report = collect(checklists()->report($checklist->fresh()))->keyBy('key');

    expect($report['retention_collected']['cleared_by'])->toBe($accountant->name)
        ->and($report['retention_collected']['cleared_at'])->toStartWith('2027-07-01')
        ->and($report['retention_collected']['note'])->toBe('OR-RET-1 receipted.')
        ->and($report['retention_collected']['panel'])->toBe('financial')
        ->and($report['demobilization_complete']['cleared_by'])->toBeNull();
});

it('names every uncleared line at once', function () {
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());
    $before = count(checklists()->outstandingFor($checklist));
    checklists()->clear($checklist, 'retention_collected', User::factory()->create(), 'Receipted.');

    expect(count(checklists()->outstandingFor($checklist->fresh())))->toBe($before - 1);
});

it('refuses to close a project whose checklist is not cleared line by line', function () {
    // The checklist is not paperwork beside the close — it IS the close. A
    // project closed over an unsigned line has a report naming nobody.
    $r = closeableProject(checklist: false);
    checklists()->open($r['project'], User::factory()->create());

    expect(fn () => closeouts()->close($r['project']->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, 'close-out checklist');
});

it('refuses to close a project with no checklist at all', function () {
    // Absence is not permission — the rule P5-01 turned on, and the one a
    // project with nothing outstanding is most likely to walk past.
    $r = closeableProject(checklist: false);

    expect(fn () => closeouts()->close($r['project'], User::factory()->create()))
        ->toThrow(DomainException::class, 'no close-out checklist');
});

it('closes the project once every line is signed', function () {
    $r = closeableProject();

    expect(closeouts()->canClose($r['project']))->toBeTrue()
        ->and(closeouts()->close($r['project'], User::factory()->create())->status->value)->toBe('closed');
});

it('refuses to clear a line once the project is closed', function () {
    $r = closeableProject();
    closeouts()->close($r['project'], User::factory()->create());

    expect(fn () => checklists()->clear(
        checklists()->forProject($r['project']->fresh()), 'final_project_pl', User::factory()->create(), 'Late.',
    ))->toThrow(DomainException::class, 'is closed');
});
