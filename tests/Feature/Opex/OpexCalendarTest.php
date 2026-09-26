<?php

use App\Domain\Opex\CashAdvanceStatus;
use App\Domain\Opex\OpexStage;
use App\Models\PayrollDeduction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The OPEX calendar and the day-26 job — P4-03 and P4-04, closing F17
|--------------------------------------------------------------------------
|
| Slide 8's seven stages: day 1–25 capture → 26 cutoff → 27 coding → 28
| validation → 29 consolidation → 30 budget review → 3–5 reporting, the following
| month. PHASE-PLAN.md: "The calendar is a state machine driven by the scheduler."
|
| **A state machine, not a set of dates.** The difference is that a stage is
| entered by a transition somebody or something performed, and the transitions
| are ordered. Reading the day off the clock would mean a month whose scheduler
| did not run on the 26th silently behaves as though it had — and the cutoff is
| the stage that stops expenses being captured into a closed period.
|
| **F17 is the day-26 job**, and PHASE-PLAN.md names it exactly: "a scheduled
| cross-chain write from OPEX into HRIS... the kind of link that quietly never
| ships." Unliquidated advances at cutoff become a payroll deduction. Two things
| make that safe: the advance is stamped so it cannot be swept twice, and the
| deduction is a real row the next payroll run reads — not a note somebody is
| expected to act on.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-26 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('opens a period in the capture stage', function () {
    // Capture is where a period begins, because slide 8's day 1 is a capture day.
    [$project] = budgetedProject();

    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);

    expect($period->stage)->toBe(OpexStage::Capture)
        ->and($period->period_year)->toBe(2026)
        ->and($period->period_month)->toBe(5);
});

it('refuses to open one period twice', function () {
    [$project] = budgetedProject();
    $organization = $project->organization()->sole();

    opexCalendar()->open($organization, 2026, 5);

    expect(fn () => opexCalendar()->open($organization, 2026, 5))
        ->toThrow(QueryException::class);
});

it('advances through slide 8 stages in order', function () {
    [$project] = budgetedProject();
    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);

    foreach ([OpexStage::Cutoff, OpexStage::Coding, OpexStage::Validation, OpexStage::Consolidation, OpexStage::BudgetReview] as $stage) {
        $period = opexCalendar()->advanceTo($period->fresh(), $stage, User::factory()->create());
        expect($period->stage)->toBe($stage);
    }

    expect($period->stage)->toBe(OpexStage::BudgetReview);
});

it('refuses to skip a stage', function () {
    // THE POINT of a state machine. Jumping capture straight to consolidation
    // means nobody coded or validated anything, and the consolidation would be
    // built from whatever happened to be captured.
    [$project] = budgetedProject();
    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);

    expect(fn () => opexCalendar()->advanceTo($period, OpexStage::Consolidation, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses to go backwards', function () {
    // Reopening a period that has been consolidated changes numbers somebody has
    // already reported. A correction is a later period's business.
    [$project] = budgetedProject();
    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);
    $period = opexCalendar()->advanceTo($period, OpexStage::Cutoff, User::factory()->create());

    expect(fn () => opexCalendar()->advanceTo($period->fresh(), OpexStage::Capture, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('records who advanced each stage and when', function () {
    // A calendar nobody signed is a calendar that cannot answer "who closed May".
    [$project] = budgetedProject();
    $user = User::factory()->create();

    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);
    $period = opexCalendar()->advanceTo($period, OpexStage::Cutoff, $user);

    $transition = $period->transitions()->latest('id')->sole();

    expect($transition->to_stage)->toBe(OpexStage::Cutoff)
        ->and($transition->from_stage)->toBe(OpexStage::Capture)
        ->and($transition->performed_by_user_id)->toBe($user->getKey());
});

it('charges an unliquidated advance to payroll at cutoff', function () {
    // F17 — THE CROSS-CHAIN WRITE, and the one PHASE-PLAN.md says quietly never
    // ships. ₱10,000 advanced, ₱8,400 liquidated, ₱1,600 outstanding at cutoff.
    [$employee, $project, $costCode] = advanceHolder();
    $organization = $project->organization()->sole();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Week 19.');
    advances()->liquidateWithExpense($advance, $costCode, '8400.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-F17-1');

    $period = opexCalendar()->open($organization, 2026, 5);
    $charged = opexCalendar()->advanceTo($period, OpexStage::Cutoff, User::factory()->create());

    $deduction = PayrollDeduction::query()->where('employee_id', $employee->getKey())->sole();

    expect((string) $deduction->amount)->toBe('1600.0000')
        ->and($deduction->cash_advance_id)->toBe($advance->getKey())
        ->and($advance->fresh()->status)->toBe(CashAdvanceStatus::ChargedToPayroll)
        ->and((string) $advance->fresh()->charged_amount)->toBe('1600.0000');
});

it('charges nothing for an advance that was fully liquidated', function () {
    // The whole point of liquidating. A job that swept every advance regardless
    // would deduct money the employee already accounted for.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '5000.0000', Carbon::parse('2026-05-04'), 'Week 19.');
    advances()->liquidateWithExpense($advance, $costCode, '5000.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-F17-2');

    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);
    opexCalendar()->advanceTo($period, OpexStage::Cutoff, User::factory()->create());

    expect(PayrollDeduction::query()->count())->toBe(0)
        ->and($advance->fresh()->status)->toBe(CashAdvanceStatus::Liquidated);
});

it('never sweeps one advance twice', function () {
    // The failure a scheduled job introduces if it is careless: run it again — a
    // retry, a second scheduler, somebody clicking twice — and the employee is
    // deducted the same money again.
    [$employee, $project, $costCode] = advanceHolder();
    $organization = $project->organization()->sole();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Week 19.');
    advances()->liquidateWithExpense($advance, $costCode, '8400.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-F17-3');

    $may = opexCalendar()->open($organization, 2026, 5);
    opexCalendar()->advanceTo($may, OpexStage::Cutoff, User::factory()->create());

    // Next month's cutoff must not find it again.
    $june = opexCalendar()->open($organization, 2026, 6);
    opexCalendar()->advanceTo($june, OpexStage::Cutoff, User::factory()->create());

    expect(PayrollDeduction::query()->where('employee_id', $employee->getKey())->count())->toBe(1);
});

it('refuses to liquidate an advance that was already charged to payroll', function () {
    // Receipts arriving after the sweep are a payroll correction, not a
    // liquidation — the money has already left OPEX.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Week 19.');

    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);
    opexCalendar()->advanceTo($period, OpexStage::Cutoff, User::factory()->create());

    expect(fn () => advances()->liquidateWithExpense($advance->fresh(), $costCode, '1000.0000', Carbon::parse('2026-05-20'), 'Late receipt', 'OR-F17-4'))
        ->toThrow(DomainException::class);
});

it('does not sweep an advance released after the cutoff date', function () {
    // The job runs on the 26th and must not reach into the days after it — those
    // advances belong to the next period's sweep.
    [$employee, $project] = advanceHolder();

    advances()->release($employee, $project, '3000.0000', Carbon::parse('2026-05-28'), 'Week 22.');

    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);
    opexCalendar()->advanceTo($period, OpexStage::Cutoff, User::factory()->create());

    expect(PayrollDeduction::query()->count())->toBe(0);
});

it('leaves the deduction unapplied until a payroll run takes it', function () {
    // The deduction is a real row the next run reads, not a note somebody is
    // expected to act on — which is exactly the difference PHASE-PLAN.md warns
    // about when it says this link quietly never ships.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Week 19.');
    advances()->liquidateWithExpense($advance, $costCode, '8400.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-F17-5');

    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);
    opexCalendar()->advanceTo($period, OpexStage::Cutoff, User::factory()->create());

    $deduction = PayrollDeduction::query()->sole();

    expect($deduction->applied_at)->toBeNull()
        ->and($deduction->payroll_line_id)->toBeNull();
});

it('closes capture at cutoff so nothing books into a shut period', function () {
    // The cutoff stage is what P0-08's calendar has always been for, reached
    // from the OPEX chain: an expense dated inside May cannot be captured once
    // May has been cut off.
    [$project, $costCode] = budgetedProject();

    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);
    opexCalendar()->advanceTo($period, OpexStage::Cutoff, User::factory()->create());

    expect(opexCalendar()->isCaptureOpen($project->organization()->sole(), 2026, 5))->toBeFalse()
        ->and(opexCalendar()->isCaptureOpen($project->organization()->sole(), 2026, 6))->toBeTrue();
});
