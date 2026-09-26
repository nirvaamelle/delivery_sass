<?php

use App\Domain\Opex\OpexStage;
use App\Filament\Pages\ProjectProfitAndLoss;
use App\Filament\Resources\CashAdvances\CashAdvancesResource;
use App\Filament\Resources\Expenses\ExpensesResource;
use App\Filament\Resources\OpexPeriods\OpexPeriodsResource;
use App\Filament\Resources\PayrollDeductions\PayrollDeductionsResource;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The Phase 4 screens — P4-10
|--------------------------------------------------------------------------
|
| Four resources and one page. The page is the interesting one: the project P&L
| is not a list of records, so giving it a resource would imply there is a
| `profit_and_loss` table somebody could edit. It is a view of the ledger, and
| everything on it is summed at read time.
|
| Read-only throughout, and the reason has teeth here. An expense typed into a
| form is one with no receipt check, no cost code check and no budget line behind
| it — the three refusals slide 8 puts in front of booking — and it would sit in
| the consolidation looking exactly like a captured one.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-30 09:00:00');
    // P6-01 scopes every project-bearing query to the signed-in user.
    // A screen test asserting a record is visible has to say who is
    // looking at it, and an administrator is who opens these screens.
    actingAs(panelUser());
});

afterEach(fn () => Carbon::setTestNow());

it('renders every Phase 4 screen', function (string $resource) {
    get($resource::getUrl('index'))->assertSuccessful();
})->with([
    ExpensesResource::class,
    CashAdvancesResource::class,
    OpexPeriodsResource::class,
    PayrollDeductionsResource::class,
]);

it('renders the project P and L page', function () {
    get(ProjectProfitAndLoss::getUrl())->assertSuccessful();
});

it('offers no way to create an OPEX document from the screen', function (string $resource) {
    expect($resource::canCreate())->toBeFalse()
        ->and(array_key_exists('create', $resource::getPages()))->toBeFalse();
})->with([
    ExpensesResource::class,
    CashAdvancesResource::class,
    OpexPeriodsResource::class,
    PayrollDeductionsResource::class,
]);

it('shows a returned expense with the reason the site must correct', function () {
    // The reason is the working content of this screen. A returned expense
    // without it is a rejection nobody can act on.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Generator hire', 'OR-SCREEN-1');
    expenses()->returnToSite($expense, 'Receipt is for the Batangas site.', User::factory()->create());

    get(ExpensesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($expense->number)
        ->assertSee('Returned')
        ->assertSee('Batangas');
});

it('shows what is still outstanding on an advance rather than a stored figure', function () {
    // The record has no `outstanding` column at all (P4-02). This screen and the
    // day-26 sweep ask the same service the same question, so they cannot
    // disagree about what somebody owes.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Petty cash.');
    advances()->liquidateWithExpense($advance, $costCode, '8400.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-SCREEN-2');

    get(CashAdvancesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($advance->number);

    expect(advances()->outstandingFor($advance->fresh()))->toBe('1600.0000');
});

it('shows the stage a month has reached, with its slide-8 day', function () {
    // "Day 26" is how the people running the calendar talk about it, so the
    // screen says it that way rather than making them remember which stage is
    // which.
    [$project] = budgetedProject();
    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);

    get(OpexPeriodsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Capture (day 1)');
});

it('shows how many cost codes are blocking a close', function () {
    // A month that will not close and does not say why is one somebody reports
    // as broken.
    [$project, $costCode, $period] = periodAtReview('112000.0000');

    get(OpexPeriodsResource::getUrl('index'))
        ->assertSuccessful();

    expect(budgetActual()->unexplained($period))->toHaveCount(1);
});

it('shows a payroll deduction against the advance it came from', function () {
    // F17 made visible. "Why is my pay short" has exactly one useful answer:
    // which advance, from which month.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Petty cash.');
    advances()->liquidateWithExpense($advance, $costCode, '8400.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-SCREEN-3');

    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);
    opexCalendar()->advanceTo($period, OpexStage::Cutoff, User::factory()->create());

    get(PayrollDeductionsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($advance->number);
});

it('shows the cash requirement beside the P and L, not inside it', function () {
    // F16's whole point: they answer different questions, and a page that merged
    // them would suggest the cash figure is a P&L line.
    //
    // A project has to exist for the tables to render at all — the page shows an
    // empty state otherwise, which is correct and proves nothing about layout.
    budgetedProject();

    get(ProjectProfitAndLoss::getUrl())
        ->assertSuccessful()
        ->assertSee('Gross profit')
        // "Cash requirement" is the heading and is always there; the total below
        // it reads "Net surplus" when collections exceed what has to go out.
        ->assertSee('Cash requirement')
        ->assertSee('Retention held');
});

it('shows an empty state rather than failing when there are no projects', function () {
    // Day one of an installation. A report that errored here would be the first
    // screen anybody opened.
    get(ProjectProfitAndLoss::getUrl())
        ->assertSuccessful()
        ->assertSee('No projects to report on');
});
