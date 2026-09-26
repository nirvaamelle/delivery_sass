<?php

use App\Domain\Gates\GateFailedException;
use App\Domain\Hris\DtrStatus;
use App\Domain\Hris\PayrollRunStatus;
use App\Models\DailyTimeRecord;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The payroll register variance gate — P3-09, closing F10
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md: "Step 7 requires 'variance against last cutoff explained per
| project' before the payroll register is approved. PLAN.md §5 has a
| variance-explanation gate for the OPEX month-end close but not for payroll.
| This is a second, independent variance gate."
|
| Two words carry the finding, and each gets its own test.
|
| **"Per project."** A run-level comparison hides exactly the movement the review
| exists to catch. If labour cost moves ₱1,200 from project A to project B, the
| run total is unchanged and a total-only check waves it through — while one
| project's cost per unit is overstated and the other's understated, and both
| numbers go into the ledger.
|
| **"Explained."** Not acknowledged, not ticked: a written reason, per project,
| recorded against the run before it can be approved. And the gate BLOCKS — a
| variance review that can be skipped is a variance review that is skipped on
| the busy cutoffs, which are the ones with the variances.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-06-02 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('approves an organization first run as a baseline with nothing to explain', function () {
    // There is no last cutoff to vary against. The first run sets the baseline.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);

    $run = computedRun($employee, '2026-05-01', '2026-05-15');

    expect(payrollVariance()->variancesFor($run))->toBe([])
        ->and(payroll()->approve($run, User::factory()->create())->status)->toBe(PayrollRunStatus::Approved);
});

it('stamps every paid day as paid when the register is approved', function () {
    // Approval, not computation, is what makes a day paid. A computed run can
    // still be refused, and a day marked paid by a run that never went out is a
    // day the employee is never paid for.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);

    $run = computedRun($employee, '2026-05-01', '2026-05-15');

    $day = DailyTimeRecord::query()->where('employee_id', $employee->getKey())->sole();
    expect($day->status)->toBe(DtrStatus::Validated);

    payroll()->approve($run, User::factory()->create());

    expect($day->fresh()->status)->toBe(DtrStatus::Paid)
        ->and($day->fresh()->paid_in_period_end->toDateString())->toBe('2026-05-15');
});

it('refuses approval while a project variance against the last cutoff is unexplained', function () {
    // THE GATE. Two days on project A last cutoff, three this cutoff. Nobody has
    // said why, so the register does not go out.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    workedDays($employee, $project, ['2026-05-18', '2026-05-19', '2026-05-20']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    expect(payrollVariance()->unexplained($second))->toBe([$project->getKey()])
        ->and(fn () => payroll()->approve($second, User::factory()->create()))
        ->toThrow(GateFailedException::class);

    expect($second->fresh()->status)->toBe(PayrollRunStatus::Computed);
});

it('approves once the variance is explained in writing', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    workedDays($employee, $project, ['2026-05-18', '2026-05-19', '2026-05-20']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    payrollVariance()->explain($second, $project, 'Added a day for the pier 7 pour.', User::factory()->create());

    expect(payroll()->approve($second, User::factory()->create())->status)->toBe(PayrollRunStatus::Approved);
});

it('compares per project, so an unchanged run total still blocks', function () {
    // THE ONE THAT MATTERS, and the reason F10 says "per project". Last cutoff:
    // two days on A, two on B. This cutoff: three on A, one on B. Four days both
    // times — the run total has not moved by a centavo, and a total-only check
    // would approve it. But A's cost is up ₱1,200 and B's down ₱1,200, and both
    // figures go into each project's cost per unit.
    $employee = contractedEmployee();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    workedDays($employee, $projectA, ['2026-05-04', '2026-05-05']);
    workedDays($employee, $projectB, ['2026-05-06', '2026-05-07']);
    $first = computedRun($employee, '2026-05-01', '2026-05-15');
    payroll()->approve($first, User::factory()->create());

    workedDays($employee, $projectA, ['2026-05-18', '2026-05-19', '2026-05-20']);
    workedDays($employee, $projectB, ['2026-05-21']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    expect((string) $second->gross_total)->toBe((string) $first->fresh()->gross_total);

    // Explaining one project is not explaining the register.
    payrollVariance()->explain($second, $projectA, 'Pour moved crew onto A.', User::factory()->create());

    expect(payrollVariance()->unexplained($second))->toBe([$projectB->getKey()])
        ->and(fn () => payroll()->approve($second, User::factory()->create()))
        ->toThrow(GateFailedException::class);
});

it('requires an explanation for a project that had no labour cost last cutoff', function () {
    // A new project appearing on the register is a variance from zero, and it is
    // the one most worth a sentence: labour charged somewhere it was not before.
    $employee = contractedEmployee();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    workedDays($employee, $projectA, ['2026-05-04', '2026-05-05']);
    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    workedDays($employee, $projectA, ['2026-05-18', '2026-05-19']);
    workedDays($employee, $projectB, ['2026-05-20']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    expect(payrollVariance()->unexplained($second))->toBe([$projectB->getKey()]);
});

it('refuses a blank explanation', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    workedDays($employee, $project, ['2026-05-18', '2026-05-19']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    expect(fn () => payrollVariance()->explain($second, $project, '   ', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses to explain a project whose cost did not vary', function () {
    // An explanation with nothing to explain is noise on the register, and it
    // would let "explained" be satisfied by pasting a sentence onto every row.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    workedDays($employee, $project, ['2026-05-18', '2026-05-19']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    expect(fn () => payrollVariance()->explain($second, $project, 'Nothing changed.', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses a second explanation for one project on one run', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    workedDays($employee, $project, ['2026-05-18', '2026-05-19']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    payrollVariance()->explain($second, $project, 'Extra day.', User::factory()->create());

    expect(fn () => payrollVariance()->explain($second->fresh(), $project, 'A different story.', User::factory()->create()))
        ->toThrow(QueryException::class);
});

it('refuses an explanation added after the register was approved', function () {
    // Explanations belong to the review. Written after approval, they justify a
    // decision rather than inform it.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = computedRun($employee, '2026-05-01', '2026-05-15');
    payroll()->approve($run, User::factory()->create());

    expect(fn () => payrollVariance()->explain($run->fresh(), $project, 'Late note.', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses to approve a register that was never computed', function () {
    $employee = contractedEmployee();
    $run = payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    expect(fn () => payroll()->approve($run, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('does not require an explanation for a variance inside a configured tolerance', function () {
    // PLACEHOLDER: B3. The deck's literal rule is that every variance is
    // explained, and the default tolerance of zero keeps that. A client whose SOP
    // allows, say, 50% is a config change — and a variance sitting exactly ON the
    // tolerance is within it.
    config()->set('payroll.variance.tolerance_percent', '50.00');

    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    // 2,400 to 3,600: a variance of exactly 50%.
    workedDays($employee, $project, ['2026-05-18', '2026-05-19', '2026-05-20']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    expect(payrollVariance()->unexplained($second))->toBe([])
        ->and(payroll()->approve($second, User::factory()->create())->status)->toBe(PayrollRunStatus::Approved);
});

it('snapshots the figures an explanation was written against', function () {
    // The explanation has to stay readable against the numbers it explained,
    // even if a later correction changes what either run would compute today.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    workedDays($employee, $project, ['2026-05-18', '2026-05-19', '2026-05-20']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    $explanation = payrollVariance()->explain($second, $project, 'Added pour day.', User::factory()->create());

    expect((string) $explanation->previous_amount)->toBe('2400.0000')
        ->and((string) $explanation->current_amount)->toBe('3600.0000')
        ->and((string) $explanation->variance)->toBe('1200.0000');
});
