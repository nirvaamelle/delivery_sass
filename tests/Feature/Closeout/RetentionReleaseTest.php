<?php

use App\Domain\Billing\RetentionEntryType;
use App\Domain\Projects\ProjectStatus;
use App\Models\RetentionRelease;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Retention release, and the collection that closes the project — P5-07
|--------------------------------------------------------------------------
|
| Slide 9's closing rule, quoted in PLAN.md §5 and again in PHASE-PLAN.md's exit
| gate: "a project stays open in the books until retention is collected."
|
| **Released is not collected, and this task is mostly about that distinction.**
| P2-07 built the retention ledger and a `release()` that moves the balance. Read
| on its own that conflates two events which are weeks apart in practice: the
| client AGREEING the defects liability period has run, and the money actually
| arriving. A project closed on the first of those is closed on a promise.
|
| So the claim is its own document. `claim()` raises it at DLP end — the request
| the client pays against — and the ledger movement is written when the money
| lands, through the same `RetentionService::release()` P2-07 built. The balance
| therefore means what it says: what the client is still holding.
|
| **And the project cannot close while any of it is outstanding.** Not the
| retention, not an uncollected invoice, not an open demobilization. The refusal
| names all of them at once.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2027-07-01 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| Claiming at DLP end
|--------------------------------------------------------------------------
*/

it('raises a retention claim once the defects liability period has run', function () {
    $r = retentionHeld();

    $claim = retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());

    expect($claim->number)->toStartWith('RR-2027-')
        ->and($claim->amount)->toBe('242500.0000')
        ->and($claim->collected_on)->toBeNull();
});

it('refuses a claim while the defects liability period is still running', function () {
    // The period is contractual. Claiming early asks the client for money they
    // have not agreed to release, and the refusal arrives after somebody has
    // already counted it as collectible.
    $r = retentionHeld(completedOn: '2027-06-01');

    expect(fn () => retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create()))
        ->toThrow(DomainException::class, 'runs until');
});

it('refuses a claim on a contract that was never completed', function () {
    // A missing completion date is not permission — the same rule the cutoff
    // calendar follows.
    $r = retentionHeld(completedOn: null);

    expect(fn () => retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create()))
        ->toThrow(DomainException::class, 'has not started');
});

it('refuses a claim for more retention than is held', function () {
    $r = retentionHeld();

    expect(fn () => retentionReleases()->claim($r['contract'], '900000.0000', User::factory()->create()))
        ->toThrow(DomainException::class, 'Retention held is');
});

it('counts an open claim against what is left to claim', function () {
    // Two claims for the same money is one of them paid twice or one chased
    // forever. The second is refused while the first is outstanding.
    $r = retentionHeld();
    retentionReleases()->claim($r['contract'], '200000.0000', User::factory()->create());

    expect(fn () => retentionReleases()->claim($r['contract'], '100000.0000', User::factory()->create()))
        ->toThrow(DomainException::class, 'already claimed');
});

it('refuses a claim for nothing', function () {
    $r = retentionHeld();

    expect(fn () => retentionReleases()->claim($r['contract'], '0.0000', User::factory()->create()))
        ->toThrow(DomainException::class, 'positive');
});

/*
|--------------------------------------------------------------------------
| Collecting it
|--------------------------------------------------------------------------
*/

it('moves the retention ledger only when the money arrives', function () {
    // The distinction the task turns on. A claim raised is a request; the
    // balance is what the client is still holding, and they are still holding
    // it until they pay.
    $r = retentionHeld();
    $claim = retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());

    expect(retention()->balanceFor($r['project']))->toBe('242500.0000');

    retentionReleases()->collect($claim, Carbon::parse('2027-07-15'), 'OR-CHK-99120', User::factory()->create());

    expect(retention()->balanceFor($r['project']->fresh()))->toBe('0.0000');
});

it('writes the ledger movement through the retention service', function () {
    // Not around it. P2-07's service owns the ledger, and a second writer is a
    // second set of rules about what may move the balance.
    $r = retentionHeld();
    $claim = retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());
    retentionReleases()->collect($claim, Carbon::parse('2027-07-15'), 'OR-CHK-99120', User::factory()->create());

    $entries = retention()->entriesFor($r['project']->fresh());

    expect($entries)->toHaveCount(2)
        ->and($entries->last()->type)->toBe(RetentionEntryType::Released)
        ->and($entries->last()->reference)->toBe('OR-CHK-99120');
});

it('refuses a collection with no reference', function () {
    // It is the receipt the money arrived against. An unreferenced collection
    // cannot be reconciled to a bank line.
    $r = retentionHeld();
    $claim = retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());

    expect(fn () => retentionReleases()->collect($claim, Carbon::parse('2027-07-15'), '  ', User::factory()->create()))
        ->toThrow(DomainException::class, 'reference');
});

it('refuses a collection dated before the claim was raised', function () {
    $r = retentionHeld();
    $claim = retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());

    expect(fn () => retentionReleases()->collect($claim, Carbon::parse('2027-06-01'), 'OR-1', User::factory()->create()))
        ->toThrow(DomainException::class, 'before the claim');
});

it('refuses to collect the same claim twice', function () {
    $r = retentionHeld();
    $claim = retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());
    retentionReleases()->collect($claim, Carbon::parse('2027-07-15'), 'OR-1', User::factory()->create());

    expect(fn () => retentionReleases()->collect($claim->fresh(), Carbon::parse('2027-07-16'), 'OR-2', User::factory()->create()))
        ->toThrow(DomainException::class, 'already collected');
});

it('refuses a collected row with nobody against it, at the database', function () {
    $r = retentionHeld();
    $claim = retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());

    expect(fn () => RetentionRelease::query()->whereKey($claim->getKey())->update([
        'collected_on' => '2027-07-15',
        'collected_by_user_id' => null,
        'collection_reference' => 'OR-1',
    ]))->toThrow(QueryException::class);
});

it('lets a partial claim be followed by another once the first is collected', function () {
    // Partial release is ordinary. What is refused is two claims open at once,
    // not two claims.
    $r = retentionHeld();
    $first = retentionReleases()->claim($r['contract'], '200000.0000', User::factory()->create());
    retentionReleases()->collect($first, Carbon::parse('2027-07-15'), 'OR-1', User::factory()->create());

    $second = retentionReleases()->claim($r['contract'], '42500.0000', User::factory()->create());

    expect($second->amount)->toBe('42500.0000')
        ->and(retention()->balanceFor($r['project']->fresh()))->toBe('42500.0000');
});

/*
|--------------------------------------------------------------------------
| The collection that closes the project
|--------------------------------------------------------------------------
*/

it('closes the project once retention has been collected', function () {
    $r = closeableProject();

    $closed = closeouts()->close($r['project'], User::factory()->create(), 'All accounts settled.');

    expect($closed->status)->toBe(ProjectStatus::Closed)
        ->and(closeouts()->isClosed($closed))->toBeTrue();
});

it('refuses to close a project while retention is still held', function () {
    // Slide 9's closing rule, and the phase exit gate: "a project stays open in
    // the books until retention is collected."
    $r = closeableProject(collectRetention: false);

    expect(fn () => closeouts()->close($r['project'], User::factory()->create()))
        ->toThrow(DomainException::class, 'Retention of 242500.0000 is still held');
});

it('refuses to close a project while a retention claim is raised but unpaid', function () {
    // The gap this task exists to close. The ledger would read zero if the claim
    // itself moved the balance, and the project would close on a promise.
    $r = closeableProject(collectRetention: false);
    retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());

    expect(fn () => closeouts()->close($r['project']->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, 'raised but uncollected');
});

it('refuses to close a project with an invoice still outstanding', function () {
    $r = closeableProject(collectInvoice: false);

    expect(fn () => closeouts()->close($r['project'], User::factory()->create()))
        ->toThrow(DomainException::class, $r['invoice']->number);
});

it('refuses to close a project whose demobilization is not complete', function () {
    $r = closeableProject(demobilize: false);

    expect(fn () => closeouts()->close($r['project'], User::factory()->create()))
        ->toThrow(DomainException::class, 'has not been demobilized');
});

it('names every reason at once rather than the first', function () {
    // Four, not three: P5-08 made the close-out checklist a precondition of
    // closing, and a project built without the other three never gets one.
    $r = closeableProject(collectRetention: false, collectInvoice: false, demobilize: false);

    expect(count(closeouts()->outstandingFor($r['project'])))->toBe(4);
});

it('refuses to close a project twice', function () {
    $r = closeableProject();
    closeouts()->close($r['project'], User::factory()->create());

    expect(fn () => closeouts()->close($r['project']->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, 'already closed');
});
