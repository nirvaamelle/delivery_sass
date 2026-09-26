<?php

use App\Domain\Opex\ExpenseStatus;
use App\Domain\Opex\PeriodBarredException;
use App\Domain\Posting\LedgerCategory;
use App\Models\Budget;
use App\Models\CostCode;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Expense capture and the permanent bar — P4-01
|--------------------------------------------------------------------------
|
| Slide 8's control, quoted: "an expense with no receipt, no cost code, or no
| budget line is not booked — returned to the site the same day, and **cannot be
| charged to the project later**."
|
| PHASE-PLAN.md draws out what that last clause means: "a rejected expense is
| permanently barred from THAT PERIOD, not merely bounced."
|
| **Barred from the period, not from existence** — and the distinction is the
| whole task. A returned expense is not fraud; it is usually a missing receipt or
| a cost code nobody filled in. The site fixes it and submits it against the NEXT
| period, which is legitimate. What must not happen is the same expense
| reappearing in the period it was rejected from, after that period's numbers
| have been reported.
|
| Three refusals sit in front of booking, and each is a real one: no receipt, no
| cost code, no budget line. The cost code is non-nullable in the schema, which
| is the strongest of the three — PLAN.md §1 wants every document to carry one.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('captures an expense against a cost code and a budget line', function () {
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture(
        $project,
        $costCode,
        '4500.0000',
        Carbon::parse('2026-05-10'),
        'Meralco, April billing',
        'OR-88213',
        User::factory()->create(),
    );

    expect($expense->number)->toStartWith('EXP-2026-')
        ->and($expense->status)->toBe(ExpenseStatus::Captured)
        ->and((string) $expense->amount)->toBe('4500.0000');
});

it('refuses an expense with no receipt reference', function () {
    // Slide 8's first refusal. An expense with no receipt is a number somebody
    // remembers, and it is the one an auditor asks about first.
    [$project, $costCode] = budgetedProject();

    expect(fn () => expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', '   '))
        ->toThrow(DomainException::class);
});

it('refuses an expense against a cost code with no budget line', function () {
    // The third refusal. An unbudgeted cost code has availability of zero, not
    // unlimited — the same rule P0-17 established for requisitions.
    [$project] = budgetedProject();

    $unbudgeted = CostCode::factory()->create([
        'organization_id' => $project->organization_id,
        'code' => '08.99.999',
    ]);

    expect(fn () => expenses()->capture($project, $unbudgeted, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-1'))
        ->toThrow(DomainException::class);
});

it('refuses an expense whose cost code belongs to another organization', function () {
    // Cross-organization coding puts one company's cost into another company's
    // P&L, and every row still looks well-formed — the same failure P0-13 guards
    // the ledger against.
    [$project] = budgetedProject();
    $foreign = CostCode::factory()->create(['code' => '08.10.100']);

    expect(fn () => expenses()->capture($project, $foreign, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-1'))
        ->toThrow(DomainException::class);
});

it('refuses a zero or negative expense', function () {
    [$project, $costCode] = budgetedProject();

    expect(fn () => expenses()->capture($project, $costCode, '0.0000', Carbon::parse('2026-05-10'), 'Nothing', 'OR-1'))
        ->toThrow(DomainException::class);
});

it('returns an expense to the site with a reason', function () {
    // The ordinary path, and it is not a punishment: most returns are a missing
    // receipt or an uncoded line.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-1');

    $returned = expenses()->returnToSite($expense, 'Receipt is for the wrong site.', User::factory()->create());

    expect($returned->status)->toBe(ExpenseStatus::Returned)
        ->and($returned->return_reason)->toBe('Receipt is for the wrong site.');
});

it('refuses to return an expense with no reason', function () {
    // The reason is what the site corrects against. Without it a return is a
    // rejection nobody can act on.
    [$project, $costCode] = budgetedProject();
    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-1');

    expect(fn () => expenses()->returnToSite($expense, '   ', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('BARS a returned expense from the period it was rejected from', function () {
    // THE ONE THAT MATTERS. Slide 8: "cannot be charged to the project later."
    // The corrected expense comes back, against the same period, and the period
    // has already been reported on.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-1');
    expenses()->returnToSite($expense, 'No receipt attached.', User::factory()->create());

    expect(fn () => expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-12'), 'Fuel', 'OR-1'))
        ->toThrow(PeriodBarredException::class);
});

it('allows the corrected expense in a LATER period', function () {
    // The other half, and the reason the bar is per period rather than absolute.
    // A returned expense is usually a paperwork failure, not a fictitious cost —
    // barring it forever would mean the company simply never books a real
    // expense because somebody forgot a receipt in May.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-1');
    expenses()->returnToSite($expense, 'No receipt attached.', User::factory()->create());

    $corrected = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-06-03'), 'Fuel', 'OR-1');

    expect($corrected->status)->toBe(ExpenseStatus::Captured)
        ->and($corrected->period_month)->toBe(6);
});

it('bars by receipt reference, not by amount', function () {
    // The bar has to identify the EXPENSE, and two different bills for the same
    // amount in one month is ordinary. Keying on the amount would block a
    // legitimate second expense; keying on the receipt blocks the same one.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-1');
    expenses()->returnToSite($expense, 'Wrong site.', User::factory()->create());

    $different = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-12'), 'Fuel', 'OR-2');

    expect($different->status)->toBe(ExpenseStatus::Captured);
});

it('lifts the bar only when somebody clears it deliberately', function () {
    // A genuine coding error — the expense was returned by mistake — has to be
    // recoverable. But clearing is an act with a name and a note on it, not a
    // side effect of trying again. The same rule as P2-05's deduction block.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-1');
    expenses()->returnToSite($expense, 'Returned in error.', User::factory()->create());

    expenses()->clearBar($project, 'OR-1', 2026, 5, 'Returned in error; receipt was on file.', User::factory()->create());

    $recaptured = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-12'), 'Fuel', 'OR-1');

    expect($recaptured->status)->toBe(ExpenseStatus::Captured);
});

it('refuses to clear a bar with no note', function () {
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-1');
    expenses()->returnToSite($expense, 'Wrong site.', User::factory()->create());

    expect(fn () => expenses()->clearBar($project, 'OR-1', 2026, 5, '  ', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses two expenses with one receipt reference in one period', function () {
    // Booking the same receipt twice is the commonest way an expense is double
    // counted, and both rows look ordinary.
    [$project, $costCode] = budgetedProject();

    expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Fuel', 'OR-DUP');

    expect(fn () => expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-12'), 'Fuel', 'OR-DUP'))
        ->toThrow(QueryException::class);
});

it('stamps the period an expense belongs to from its own date', function () {
    // The document's date decides which period it belongs to — the same rule the
    // cutoff resolver has enforced since P0-08. An expense dated 3 May booked on
    // 27 May is a MAY expense, and late.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-03'), 'Fuel', 'OR-1');

    expect($expense->period_year)->toBe(2026)
        ->and($expense->period_month)->toBe(5);
});

it('codes an expense to an overhead category by default', function () {
    // Slide 8's inputs are utilities, rentals, permits, insurance and IT — the
    // P&L's overhead line. The category is on the expense rather than inferred
    // at posting time, because two expenses on one cost code can land in
    // different categories.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-1');

    expect($expense->category)->toBe(LedgerCategory::Overhead);
});
