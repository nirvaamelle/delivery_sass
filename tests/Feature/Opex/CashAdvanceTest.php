<?php

use App\Domain\Opex\CashAdvanceStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Cash advances and liquidation — P4-02
|--------------------------------------------------------------------------
|
| Slide 8's day-26 rule: "cash advances are liquidated **or charged to the next
| payroll**." F17 is the charging half, and it is scheduled — this task is the
| record it reads.
|
| **The unliquidated balance is derived, never stored.** The same rule the stock
| card follows and for the same reason: a stored `outstanding` column is only as
| true as the last routine that wrote it, and the day that routine fails quietly
| is the day somebody's advance is charged to payroll twice or not at all. An
| advance's balance is what it was released for, less what has been liquidated
| against it, and only the liquidations answer that.
|
| **Partial liquidation is the normal case.** Somebody draws ₱10,000 for a week
| on site and comes back with ₱8,400 of receipts and ₱1,600 in cash. Both halves
| are liquidations — one against expenses, one a cash return — and a model that
| could only settle an advance in full would force the site to round.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('releases a cash advance against a project', function () {
    [$employee, $project] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Site petty cash, week 19.', User::factory()->create());

    expect($advance->number)->toStartWith('CA-2026-')
        ->and($advance->status)->toBe(CashAdvanceStatus::Released)
        ->and((string) $advance->amount)->toBe('10000.0000');
});

it('refuses an advance with no purpose', function () {
    // Money leaving before anything is spent needs to say what it is for — the
    // same rule F8's vendor advance follows on the procurement side.
    [$employee, $project] = advanceHolder();

    expect(fn () => advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), '   '))
        ->toThrow(DomainException::class);
});

it('refuses a zero or negative advance', function () {
    [$employee, $project] = advanceHolder();

    expect(fn () => advances()->release($employee, $project, '0.0000', Carbon::parse('2026-05-04'), 'Nothing.'))
        ->toThrow(DomainException::class);
});

it('derives the outstanding balance rather than storing it', function () {
    // THE RULE. A stored balance is only as true as the last routine that wrote
    // it, and F17's day-26 job reads this number to decide what to charge to
    // payroll — so it has to be computed from the liquidations every time.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Petty cash.');

    expect(advances()->outstandingFor($advance))->toBe('10000.0000');

    advances()->liquidateWithExpense($advance, $costCode, '4500.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-CA-1', User::factory()->create());

    expect(advances()->outstandingFor($advance->fresh()))->toBe('5500.0000');
});

it('liquidates against a real expense, not a bare figure', function () {
    // A liquidation with no expense behind it is an advance written off by
    // assertion. Every peso liquidated this way carries a receipt and a cost
    // code, because it goes through the same capture path slide 8 governs.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Petty cash.');

    $liquidation = advances()->liquidateWithExpense($advance, $costCode, '4500.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-CA-2', User::factory()->create());

    expect($liquidation->expense_id)->not->toBeNull()
        ->and($liquidation->expense()->sole()->receipt_reference)->toBe('OR-CA-2');
});

it('accepts a cash return as a liquidation', function () {
    // The other half of a partial liquidation: receipts for some of it, cash back
    // for the rest. A model that only accepted expenses would force the site to
    // invent one for the change in their pocket.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Petty cash.');

    advances()->liquidateWithExpense($advance, $costCode, '8400.0000', Carbon::parse('2026-05-06'), 'Fuel and supplies', 'OR-CA-3');
    advances()->returnCash($advance->fresh(), '1600.0000', Carbon::parse('2026-05-07'), 'CR-2026-11', User::factory()->create());

    expect(advances()->outstandingFor($advance->fresh()))->toBe('0.0000')
        ->and($advance->fresh()->status)->toBe(CashAdvanceStatus::Liquidated);
});

it('refuses to liquidate more than the advance', function () {
    // Over-liquidation turns an advance into a reimbursement, which is a
    // different decision with a different approval behind it.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Petty cash.');

    expect(fn () => advances()->liquidateWithExpense($advance, $costCode, '12000.0000', Carbon::parse('2026-05-06'), 'Too much', 'OR-CA-4'))
        ->toThrow(DomainException::class);
});

it('marks an advance liquidated only when nothing is outstanding', function () {
    // Partly liquidated is a real state, and the day-26 job reads it: an advance
    // with ₱1,600 left is charged for ₱1,600, not for nothing and not for all of
    // it.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Petty cash.');
    advances()->liquidateWithExpense($advance, $costCode, '8400.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-CA-5');

    expect($advance->fresh()->status)->toBe(CashAdvanceStatus::PartlyLiquidated)
        ->and(advances()->outstandingFor($advance->fresh()))->toBe('1600.0000');
});

it('refuses to liquidate an advance that is already settled', function () {
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '5000.0000', Carbon::parse('2026-05-04'), 'Petty cash.');
    advances()->liquidateWithExpense($advance, $costCode, '5000.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-CA-6');

    expect(fn () => advances()->returnCash($advance->fresh(), '100.0000', Carbon::parse('2026-05-07'), 'CR-1'))
        ->toThrow(DomainException::class);
});

it('lists what is still unliquidated as at a date', function () {
    // Exactly what F17's day-26 job asks for, and the reason it takes a date: the
    // job runs on the 26th and must not sweep an advance released on the 27th
    // into the cutoff it already passed.
    [$employee, $project, $costCode] = advanceHolder();

    $early = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Week 19.');
    advances()->liquidateWithExpense($early, $costCode, '8400.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-CA-7');

    $late = advances()->release($employee, $project, '3000.0000', Carbon::parse('2026-05-28'), 'Week 22.');

    $unliquidated = advances()->unliquidatedAsOf($employee->organization()->sole(), Carbon::parse('2026-05-26'));

    expect($unliquidated)->toHaveCount(1)
        ->and($unliquidated->first()->number)->toBe($early->number);
});

it('excludes a fully liquidated advance from the unliquidated list', function () {
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '5000.0000', Carbon::parse('2026-05-04'), 'Petty cash.');
    advances()->liquidateWithExpense($advance, $costCode, '5000.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-CA-8');

    expect(advances()->unliquidatedAsOf($employee->organization()->sole(), Carbon::parse('2026-05-26')))
        ->toHaveCount(0);
});

it('refuses a liquidation dated before the advance was released', function () {
    // A receipt older than the money cannot have been paid for with it.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '5000.0000', Carbon::parse('2026-05-10'), 'Petty cash.');

    expect(fn () => advances()->liquidateWithExpense($advance, $costCode, '1000.0000', Carbon::parse('2026-05-04'), 'Fuel', 'OR-CA-9'))
        ->toThrow(DomainException::class);
});
