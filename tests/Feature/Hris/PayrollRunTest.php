<?php

use App\Domain\Hris\DtrStatus;
use App\Domain\Hris\OvertimeType;
use App\Domain\Hris\PayBasis;
use App\Domain\Hris\PayrollRunStatus;
use App\Jobs\ComputePayrollRun;
use App\Models\DailyTimeRecord;
use App\Models\PayrollLineDay;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| The payroll run — P3-07 and P3-08
|--------------------------------------------------------------------------
|
| Slide 7: "Gross pay computed from VALIDATED DTR only." And the held-day
| mechanic settled in P3-05 finally meets money here — a held day is paid in the
| next cutoff, at the rate that was in force on the day it was worked.
|
| Four rules carry this file:
|
|   - **a run belongs to exactly one cutoff.** A run for the 3rd to the 18th
|     straddles two, and the days in the overlap get paid by both;
|   - **validated days only.** An unvalidated day is held, not paid — and not
|     dropped either;
|   - **the rate is the day's, not the run's.** A raise on the 10th pays the 8th
|     at the old rate and the 11th at the new one, however late the run is
|     computed;
|   - **a missing rate is an exception, not a zero.** A run that paid nothing
|     for a day with no rate would look like it had worked.
|
| And one guard held by the database rather than the service: a DTR day can
| appear on ONE payroll line, ever. Double pay is the failure every payroll bug
| eventually becomes, so it gets the unique key.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-20 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('opens a run for a configured cutoff', function () {
    $employee = contractedEmployee();

    $run = payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    expect($run->number)->toStartWith('PAY-2026-')
        ->and($run->status)->toBe(PayrollRunStatus::Draft);
});

it('refuses a run that straddles two cutoffs', function () {
    // The 3rd to the 18th overlaps both halves of May, and every day in the
    // overlap would be payable by two runs.
    $employee = contractedEmployee();

    expect(fn () => payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-03'), Carbon::parse('2026-05-18')))
        ->toThrow(DomainException::class);
});

it('accepts the second cutoff ending on the last day of the month', function () {
    $employee = contractedEmployee();

    $run = payroll()->open($employee->organization()->sole(), Carbon::parse('2026-02-16'), Carbon::parse('2026-02-28'));

    expect($run->status)->toBe(PayrollRunStatus::Draft);
});

it('refuses a second run for the same cutoff', function () {
    $employee = contractedEmployee();
    $organization = $employee->organization()->sole();

    payroll()->open($organization, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    expect(fn () => payroll()->open($organization, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')))
        ->toThrow(QueryException::class);
});

it('queues the computation rather than running it in the request', function () {
    // Slide 7 says queued. A payroll for a whole organization computed inside a
    // web request is a timeout that leaves a half-computed register behind.
    Queue::fake();

    $employee = contractedEmployee();
    $run = payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    $queued = payroll()->queue($run);

    Queue::assertPushed(ComputePayrollRun::class);
    expect($queued->status)->toBe(PayrollRunStatus::Queued);
});

it('computes the run when the queued job executes', function () {
    $employee = contractedEmployee();
    $run = payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    // The test suite's queue is sync, so the job runs as it is dispatched.
    $computed = payroll()->queue($run);

    expect($computed->status)->toBe(PayrollRunStatus::Computed)
        ->and($computed->computed_at)->not->toBeNull();
});

it('computes gross pay from validated days only', function () {
    // Three days imported, two certified. Two days are paid.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05'], validate: true);
    workedDays($employee, $project, ['2026-05-06'], validate: false);

    $run = payroll()->compute(payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')));
    $line = $run->lines()->where('employee_id', $employee->getKey())->sole();

    expect((string) $line->gross_pay)->toBe('2400.0000')
        ->and($line->days_paid)->toBe(2);
});

it('computes a full cutoff through to net pay', function () {
    // Ten certified eight-hour days at 1,200 is 12,000 gross. Contributions
    // 600 + 300 + 100, tax 87.45 on the 11,000 left, net 10,912.55.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, [
        '2026-05-04', '2026-05-05', '2026-05-06', '2026-05-07', '2026-05-08',
        '2026-05-11', '2026-05-12', '2026-05-13', '2026-05-14', '2026-05-15',
    ]);

    $run = payroll()->compute(payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')));
    $line = $run->lines()->sole();

    expect((string) $line->gross_pay)->toBe('12000.0000')
        ->and((string) $line->sss_contribution)->toBe('600.0000')
        ->and((string) $line->philhealth_contribution)->toBe('300.0000')
        ->and((string) $line->pagibig_contribution)->toBe('100.0000')
        ->and((string) $line->withholding_tax)->toBe('87.4500')
        ->and((string) $line->net_pay)->toBe('10912.5500')
        ->and((string) $run->gross_total)->toBe('12000.0000');
});

it('holds an uncertified day and pays it in the next cutoff, marked as carried', function () {
    // THE MECHANIC, meeting money. May 5 is not certified by the first cutoff,
    // so the first run pays one day and holds the other. The site certifies May
    // 5 late; the second run pays it — flagged, so the payslip can say so.
    $employee = contractedEmployee();
    $project = Project::factory()->create();
    $organization = $employee->organization()->sole();

    workedDays($employee, $project, ['2026-05-04'], validate: true);
    workedDays($employee, $project, ['2026-05-05'], validate: false);

    $first = payroll()->compute(payroll()->open($organization, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')));

    expect((string) $first->lines()->sole()->gross_pay)->toBe('1200.0000');

    $held = DailyTimeRecord::query()->where('employee_id', $employee->getKey())->whereDate('work_date', '2026-05-05')->sole();
    timekeeping()->validate($held, User::factory()->create());

    $second = payroll()->compute(payroll()->open($organization, Carbon::parse('2026-05-16'), Carbon::parse('2026-05-31')));
    $line = $second->lines()->sole();

    expect((string) $line->gross_pay)->toBe('1200.0000')
        ->and($line->carried_days)->toBe(1)
        ->and($line->days()->sole()->carried)->toBeTrue()
        ->and($line->days()->sole()->work_date->toDateString())->toBe('2026-05-05');
});

it('pays authorised overtime at the premium and ignores the rest', function () {
    // Twelve hours worked, two authorised. Eight at the day rate is 1,200; two at
    // 150 × 1.25 is 375. The other two hours stay on the DTR, unpaid at premium.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04'], out: '20:00');

    $authority = overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '2.00', 'Deck pour.', User::factory()->create());
    overtime()->approve($authority, User::factory()->create(), 'SE memo 2026-05-04-02');

    $run = payroll()->compute(payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')));
    $line = $run->lines()->sole();

    expect((string) $line->basic_pay)->toBe('1200.0000')
        ->and((string) $line->overtime_pay)->toBe('375.0000')
        ->and((string) $line->gross_pay)->toBe('1575.0000');
});

it('pays each day at the rate in force on that day', function () {
    // A raise effective the 10th. The 8th is paid at 1,200 and the 11th at
    // 1,350, however late the run is computed.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    employees()->setRate($employee, PayBasis::Daily, '1350.0000', Carbon::parse('2026-05-10'));

    workedDays($employee, $project, ['2026-05-08', '2026-05-11']);

    $run = payroll()->compute(payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')));

    expect((string) $run->lines()->sole()->gross_pay)->toBe('2550.0000');
});

it('reports an employee with no rate as an exception instead of paying zero', function () {
    // Absence is not zero. The services do not currently produce a certified
    // day with no rate behind it — the contract gate and the signing step
    // prevent it — so the DTR is written directly here, as imported legacy data
    // would be. The run must refuse to price it rather than pay nothing.
    $employee = hiredEmployee(['employee_number' => 'EMP-NORATE']);

    DailyTimeRecord::query()->create([
        'employee_id' => $employee->getKey(),
        'project_id' => Project::factory()->create()->getKey(),
        'work_date' => '2026-05-04',
        'hours_worked' => '8.00',
        'status' => DtrStatus::Validated,
    ]);

    $run = payroll()->compute(payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')));

    expect($run->lines()->count())->toBe(0)
        ->and(collect($run->exceptions)->pluck('employee_number')->all())->toContain('EMP-NORATE');
});

it('refuses to put one DTR day on two payroll lines, at the database', function () {
    // Double pay is what every payroll bug eventually becomes, so the guard is a
    // unique key rather than a check a future code path could forget.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);

    $run = payroll()->compute(payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')));
    $day = $run->lines()->sole()->days()->sole();

    expect(fn () => PayrollLineDay::query()->create([
        'payroll_line_id' => $day->payroll_line_id,
        'daily_time_record_id' => $day->daily_time_record_id,
        'project_id' => $day->project_id,
        'work_date' => $day->work_date,
        'hours_regular' => '8.00',
        'hours_overtime' => '0.00',
        'hours_night' => '0.00',
        'amount' => '1200.0000',
    ]))->toThrow(QueryException::class);
});

it('refuses to compute a run twice', function () {
    $employee = contractedEmployee();
    $run = payroll()->compute(payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')));

    expect(fn () => payroll()->compute($run->fresh()))
        ->toThrow(DomainException::class);
});

it('encrypts pay amounts at rest', function () {
    // A net pay figure reveals a rate as surely as the rate column does, so the
    // same PLAN.md §3 rule applies to the line.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);

    $run = payroll()->compute(payroll()->open($employee->organization()->sole(), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15')));
    $line = $run->lines()->sole();

    $raw = DB::table('payroll_lines')->where('id', $line->getKey())->first();

    expect($raw->gross_pay)->not->toContain('2400')
        ->and((string) $line->gross_pay)->toBe('2400.0000');
});
