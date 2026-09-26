<?php

use App\Domain\Hris\DtrStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Timelogs, DTR validation and the held day — P3-04 and P3-05
|--------------------------------------------------------------------------
|
| Slide 7's rule, quoted: **"No timelog, no pay" — unvalidated days are held and
| paid in the next cutoff once the site certifies them.**
|
| PHASE-PLAN.md calls this out specifically as something settled in Phase 3: "the
| held-day mechanic — an unvalidated DTR line carries to the next period rather
| than being DROPPED, so payroll_lines can reference a prior-period DTR line."
|
| **Held is not the same as unpaid, and that distinction is the whole task.** The
| obvious implementation drops the day: it is not validated, so it is not paid,
| and the run balances. But the person worked. Dropping the day means they are
| short that fortnight and nobody has a record saying why — and the correction,
| when it comes, is somebody typing an adjustment line with no evidence behind
| it. Holding it keeps the day, keeps its reason, and pays it in the next cutoff
| once the site certifies it.
|
| The import half matters for the same reason: a biometrics CSV that silently
| skips rows it cannot parse is a payroll run missing days nobody knows about.
| Rejected rows are RETURNED, with their line numbers.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-20 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('imports biometric rows into timelogs', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    $result = timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-05', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    expect($result['imported'])->toBe(2)
        ->and($result['rejected'])->toBe([]);
});

it('returns the rows it could not import rather than skipping them silently', function () {
    // THE IMPORT RULE. A biometrics export that quietly drops rows it cannot
    // parse is a payroll run missing days nobody knows about — and the person
    // who notices is the one who was not paid.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    $result = timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
        ['employee_number' => 'EMP-NOBODY', 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
        ['employee_number' => $employee->employee_number, 'work_date' => 'not-a-date', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    expect($result['imported'])->toBe(1)
        ->and($result['rejected'])->toHaveCount(2)
        // With line numbers, so somebody can go and fix the file.
        ->and($result['rejected'][0]['line'])->toBe(2)
        ->and($result['rejected'][1]['line'])->toBe(3);
});

it('refuses a timelog for a day the employee had no signed contract', function () {
    // Slide 7's contract gate, reached from the timekeeping chain. The shift
    // still happened — which is why the row is REJECTED and reported rather
    // than discarded.
    $employee = hiredEmployee(['employee_number' => 'EMP-NOCONTRACT']);
    $project = Project::factory()->create();

    $result = timekeeping()->import($project, [
        ['employee_number' => 'EMP-NOCONTRACT', 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    expect($result['imported'])->toBe(0)
        ->and($result['rejected'][0]['reason'])->toContain('contract');
});

it('refuses two timelogs for one person on one day', function () {
    // A double punch imported twice is a day paid twice, and both rows look
    // ordinary.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    $result = timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    expect($result['imported'])->toBe(0)
        ->and($result['rejected'])->toHaveCount(1);
});

it('builds a DTR line per imported day, unvalidated', function () {
    // Unvalidated is where every day starts. "No timelog, no pay" means the site
    // certifies the day, and a DTR that arrived pre-certified would make the
    // certification a formality.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    $dtr = timekeeping()->dtrFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    expect($dtr)->toHaveCount(1)
        ->and($dtr->first()->status)->toBe(DtrStatus::Unvalidated);
});

it('computes hours worked from the punches', function () {
    // 07:00 to 17:00 less an hour's break is eight, which is the number gross
    // pay is built from.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00', 'break_minutes' => 60],
    ]);

    $line = timekeeping()->dtrFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'))->first();

    expect((string) $line->hours_worked)->toBe('9.00');
});

it('validates a day when the site certifies it', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    $line = timekeeping()->dtrFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'))->first();
    $validated = timekeeping()->validate($line, User::factory()->create());

    expect($validated->status)->toBe(DtrStatus::Validated)
        ->and($validated->validated_at)->not->toBeNull();
});

it('HOLDS an unvalidated day rather than dropping it', function () {
    // THE ONE THAT MATTERS, and the mechanic PHASE-PLAN.md says is settled here.
    //
    // The obvious implementation drops the day: it is not validated, so it is
    // not paid, and the run balances. But the person WORKED. Dropping the day
    // leaves them short that fortnight with no record saying why, and the
    // correction is somebody typing an adjustment with no evidence behind it.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-05', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    $lines = timekeeping()->dtrFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));
    timekeeping()->validate($lines->first(), User::factory()->create());

    // Closing the cutoff holds what the site did not certify.
    $held = timekeeping()->closeCutoff($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    expect($held)->toHaveCount(1)
        ->and($held->first()->status)->toBe(DtrStatus::Held)
        // The day is still there, with its date intact.
        ->and($held->first()->work_date->toDateString())->toBe('2026-05-05');
});

it('carries a held day into the next cutoff once it is certified', function () {
    // The second half of the mechanic: the held day is PAYABLE in the next
    // cutoff, not merely visible. That is what makes `payroll_lines` able to
    // reference a prior-period DTR line.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-05', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    $lines = timekeeping()->dtrFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));
    timekeeping()->closeCutoff($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    // Late certification, in the following period.
    timekeeping()->validate($lines->first()->fresh(), User::factory()->create());

    $payable = timekeeping()->payableFor($employee->fresh(), Carbon::parse('2026-05-16'), Carbon::parse('2026-05-31'));

    expect($payable)->toHaveCount(1)
        ->and($payable->first()->work_date->toDateString())->toBe('2026-05-05');
});

it('does not pay a held day that is still uncertified', function () {
    // Held is not a way of paying it later regardless. The site still has to
    // certify it — "no timelog, no pay" survives the mechanic.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-05', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    timekeeping()->closeCutoff($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    expect(timekeeping()->payableFor($employee->fresh(), Carbon::parse('2026-05-16'), Carbon::parse('2026-05-31')))
        ->toHaveCount(0);
});

it('does not pay the same day in two cutoffs', function () {
    // The failure the held-day mechanic could introduce if it were careless: a
    // day paid in its own period and again as a carry-over.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-05', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    $line = timekeeping()->dtrFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'))->first();
    timekeeping()->validate($line, User::factory()->create());

    $first = timekeeping()->payableFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));
    timekeeping()->markPaid($first, Carbon::parse('2026-05-15'));

    $second = timekeeping()->payableFor($employee->fresh(), Carbon::parse('2026-05-16'), Carbon::parse('2026-05-31'));

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(0);
});

it('refuses to validate a day twice', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    $line = timekeeping()->dtrFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'))->first();
    timekeeping()->validate($line, User::factory()->create());

    expect(fn () => timekeeping()->validate($line->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('rejects a day with a reason, and does not pay it at all', function () {
    // Rejection is a third outcome and not the same as holding: a day that never
    // happened must not carry forward waiting to be certified.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    $line = timekeeping()->dtrFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'))->first();
    timekeeping()->reject($line, User::factory()->create(), 'Biometric punch from the wrong site.');

    timekeeping()->closeCutoff($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    expect($line->fresh()->status)->toBe(DtrStatus::Rejected)
        ->and(timekeeping()->payableFor($employee->fresh(), Carbon::parse('2026-05-16'), Carbon::parse('2026-05-31')))
        ->toHaveCount(0);
});

it('refuses a rejection with no reason', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '17:00'],
    ]);

    $line = timekeeping()->dtrFor($employee->fresh(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'))->first();

    expect(fn () => timekeeping()->reject($line, User::factory()->create(), '   '))
        ->toThrow(DomainException::class);
});

it('refuses a timelog whose punch-out precedes its punch-in', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    $result = timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '17:00', 'time_out' => '07:00'],
    ]);

    expect($result['imported'])->toBe(0)
        ->and($result['rejected'])->toHaveCount(1);
});
