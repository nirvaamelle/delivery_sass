<?php

use App\Domain\Cutoffs\CutoffClosedException;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Gates\GateFailedException;
use App\Domain\Opex\ExpenseStatus;
use App\Domain\Opex\OpexStage;
use App\Domain\Posting\LedgerCategory;
use App\Models\CutoffCalendar;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Budget vs actual, the close gate, and overhead posting — P4-05 to P4-07
|--------------------------------------------------------------------------
|
| Slide 8's day 30: "variance above 10% explained in writing." PHASE-PLAN.md
| calls this the OPEX half of the pair whose payroll twin is F10 — two
| independent variance gates, because the chains close on different calendars and
| answer to different reviewers.
|
| **Above 10%, so 10% exactly is inside it.** A threshold that fires on its own
| boundary blocks a close that met the standard, and the person who has to explain
| it has nothing to say beyond "it is exactly ten".
|
| **And the gate BLOCKS the close**, rather than warning. A month-end review that
| can be skipped is skipped in the months with the variances — which are the
| months worth reviewing.
|
| P4-07 is the third clause of the phase exit gate: overhead reaches the ledger,
| per cost code, on the OPEX calendar. It is the fourth chain to arrive at
| PLAN.md §1's organising table.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-30 09:00:00');

    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Opex,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'cutoff_at' => '2026-12-31 17:00:00',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('compares actual against budget per cost code', function () {
    [$project, $costCode] = budgetedProject('100000.0000');

    expenses()->capture($project, $costCode, '45000.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-BA-1');

    $row = collect(budgetActual()->forPeriod($project, 2026, 5))->firstWhere('cost_code_id', $costCode->getKey());

    expect($row['budget'])->toBe('100000.0000')
        ->and($row['actual'])->toBe('45000.0000')
        ->and($row['variance'])->toBe('-55000.0000')
        ->and($row['variance_percent'])->toBe('-55.00');
});

it('ignores a returned expense in the actual', function () {
    // Slide 8: a returned expense is "not booked". Counting it would put a cost
    // the company refused into the budget comparison.
    [$project, $costCode] = budgetedProject('100000.0000');

    $expense = expenses()->capture($project, $costCode, '45000.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-BA-2');
    expenses()->returnToSite($expense, 'Wrong site.', User::factory()->create());

    $row = collect(budgetActual()->forPeriod($project, 2026, 5))->firstWhere('cost_code_id', $costCode->getKey());

    expect($row['actual'])->toBe('0.0000');
});

it('reports an unbudgeted overspend as a variance from zero', function () {
    // A cost code with no budget line cannot be captured against at all (P4-01),
    // so this is the case where a budget was reduced after spending began. It
    // must not divide by zero, and it must not read as "no variance".
    [$project, $costCode] = budgetedProject('0.0001');

    expenses()->capture($project, $costCode, '45000.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-BA-3');

    $row = collect(budgetActual()->forPeriod($project, 2026, 5))->firstWhere('cost_code_id', $costCode->getKey());

    expect($row['variance_percent'])->not->toBeNull()
        ->and(bccomp($row['variance_percent'], '1000.00', 2))->toBeGreaterThan(0);
});

it('does not require an explanation at exactly ten percent', function () {
    // THE BOUNDARY. Slide 8 says ABOVE 10%, so 10% exactly is inside it — and a
    // gate that fires on its own boundary blocks a close that met the standard.
    [$project, $costCode, $period] = periodAtReview('110000.0000');

    expect(budgetActual()->unexplained($period))->toBe([])
        ->and(opexCalendar()->advanceTo($period, OpexStage::Reporting, User::factory()->create())->stage)
        ->toBe(OpexStage::Reporting);
});

it('BLOCKS the close on a twelve percent variance until it is explained', function () {
    // THE GATE, and the phase exit gate's own figure. 112,000 against a 100,000
    // line is 12% over.
    [$project, $costCode, $period] = periodAtReview('112000.0000');

    expect(budgetActual()->unexplained($period))->toBe([$costCode->getKey()])
        ->and(fn () => opexCalendar()->advanceTo($period, OpexStage::Reporting, User::factory()->create()))
        ->toThrow(GateFailedException::class);

    expect($period->fresh()->stage)->toBe(OpexStage::BudgetReview);
});

it('lets the close proceed once the variance is explained in writing', function () {
    [$project, $costCode, $period] = periodAtReview('112000.0000');

    budgetActual()->explain($period, $costCode, 'Generator hire extended three weeks by the deck pour.', User::factory()->create());

    expect(opexCalendar()->advanceTo($period->fresh(), OpexStage::Reporting, User::factory()->create())->stage)
        ->toBe(OpexStage::Reporting);
});

it('blocks on an underspend as well as an overspend', function () {
    // A project 40% under budget is as interesting as one 40% over: either the
    // work is not being done or the budget was wrong, and both need a sentence.
    [$project, $costCode, $period] = periodAtReview('60000.0000');

    expect(budgetActual()->unexplained($period))->toBe([$costCode->getKey()]);
});

it('refuses a blank explanation', function () {
    [$project, $costCode, $period] = periodAtReview('112000.0000');

    expect(fn () => budgetActual()->explain($period, $costCode, '   ', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses to explain a cost code whose variance is inside the threshold', function () {
    // An explanation with nothing to explain is noise, and allowing it would let
    // "explained" be satisfied by pasting a sentence onto every row.
    [$project, $costCode, $period] = periodAtReview('105000.0000');

    expect(fn () => budgetActual()->explain($period, $costCode, 'Nothing happened.', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses a second explanation for one cost code in one period', function () {
    [$project, $costCode, $period] = periodAtReview('112000.0000');

    budgetActual()->explain($period, $costCode, 'Generator hire.', User::factory()->create());

    expect(fn () => budgetActual()->explain($period->fresh(), $costCode, 'A different story.', User::factory()->create()))
        ->toThrow(QueryException::class);
});

it('snapshots the figures an explanation was written against', function () {
    // The explanation has to stay readable beside the numbers it explained, even
    // if a later correction changes what the period would compute today.
    [$project, $costCode, $period] = periodAtReview('112000.0000');

    $explanation = budgetActual()->explain($period, $costCode, 'Generator hire.', User::factory()->create());

    expect((string) $explanation->budget_amount)->toBe('100000.0000')
        ->and((string) $explanation->actual_amount)->toBe('112000.0000')
        ->and((string) $explanation->variance_percent)->toBe('12.00');
});

it('posts overhead to the ledger, one row per cost code', function () {
    // P4-07, and the fourth chain to reach PLAN.md §1's organising table.
    [$project, $costCode] = budgetedProject('100000.0000');

    expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-OH-1');
    expenses()->capture($project, $costCode, '2500.0000', Carbon::parse('2026-05-12'), 'Maynilad', 'OR-OH-2');

    $entries = overheadPoster()->postPeriod($project, 2026, 5);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->category)->toBe(LedgerCategory::Overhead)
        ->and($entries[0]->amount)->toBe('7000.0000')
        ->and($entries[0]->cost_code)->toBe($costCode->code);
});

it('marks each expense posted so none is booked twice', function () {
    [$project, $costCode] = budgetedProject('100000.0000');

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-OH-3');

    overheadPoster()->postPeriod($project, 2026, 5);

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Posted)
        ->and($expense->fresh()->posted_at)->not->toBeNull();

    // A second run finds nothing left to post.
    expect(fn () => overheadPoster()->postPeriod($project, 2026, 5))
        ->toThrow(DomainException::class);
});

it('posts on the OPEX calendar, not the billing one', function () {
    // F3, from the fourth chain. The OPEX period cuts off on day 26 — before the
    // month it closes has even finished — and judging an expense by the billing
    // calendar would judge it by the wrong date entirely.
    CutoffCalendar::query()->where('cutoff_type', CutoffType::Opex)->update([
        'cutoff_at' => '2026-05-26 17:00:00',
    ]);

    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Billing,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'cutoff_at' => '2026-12-31 17:00:00',
    ]);

    [$project, $costCode] = budgetedProject('100000.0000');
    expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-OH-4');

    expect(fn () => overheadPoster()->postPeriod($project, 2026, 5))
        ->toThrow(CutoffClosedException::class);
});

it('puts the overhead row in the ledger the P and L is assembled from', function () {
    [$project, $costCode] = budgetedProject('100000.0000');
    expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-OH-5');

    overheadPoster()->postPeriod($project, 2026, 5);

    expect(ProjectCostLedgerEntry::query()
        ->where('category', LedgerCategory::Overhead)
        ->where('cost_code', $costCode->code)
        ->exists())->toBeTrue();
});
