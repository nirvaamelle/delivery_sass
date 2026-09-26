<?php

/*
|--------------------------------------------------------------------------
| Shared HRIS fixtures
|--------------------------------------------------------------------------
|
| Same reason as the procurement and billing fixtures, learned the hard way four
| times already: a helper declared in a test file is global to every other test
| file, so a suite only passes when run beside its neighbours, and the second
| suite to want the name dies with a fatal redeclare rather than a failure.
|
| Payroll makes this worse than the other chains did, because almost nothing can
| be tested in isolation: a DTR line needs a signed contract, which needs an
| employee, which needs an organization — and the contract gate refuses the
| shortcut on purpose.
|
*/

use App\Domain\Hris\DisbursementService;
use App\Domain\Hris\EmployeeService;
use App\Domain\Hris\HiringService;
use App\Domain\Hris\OvertimeService;
use App\Domain\Hris\PayBasis;
use App\Domain\Hris\PayrollService;
use App\Domain\Hris\PayrollVarianceService;
use App\Domain\Hris\PayslipService;
use App\Domain\Hris\StatutoryCalculator;
use App\Domain\Hris\TimekeepingService;
use App\Domain\Posting\LaborCostPoster;
use App\Models\DailyTimeRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

function employees(): EmployeeService
{
    return app(EmployeeService::class);
}

function hiredEmployee(array $overrides = []): Employee
{
    return employees()->hire(
        Organization::factory()->create(),
        array_merge([
            'employee_number' => 'EMP-'.uniqid(),
            'first_name' => 'Marisol',
            'last_name' => 'Reyes',
            'date_hired' => '2026-02-01',
            'position' => 'Carpenter',
            'sss_number' => '34-5678901-2',
            'philhealth_number' => '12-345678901-2',
            'pagibig_number' => '1234-5678-9012',
            'tin' => '123-456-789-000',
            'bank_account_number' => '001234567890',
        ], $overrides),
    );
}

function hiring(): HiringService
{
    return app(HiringService::class);
}

function timekeeping(): TimekeepingService
{
    return app(TimekeepingService::class);
}

/**
 * An employee with a signed contract in force, ready to work.
 */
function contractedEmployee(string $number = 'EMP-T1'): Employee
{
    $employee = hiredEmployee(['employee_number' => $number, 'date_hired' => '2026-04-01']);

    $contract = hiring()->issueContract($employee, Carbon::parse('2026-04-01'), null, PayBasis::Daily, '1200.0000');
    hiring()->signContract($contract, Carbon::parse('2026-03-30'), 'M. Reyes');

    return $employee->fresh();
}

function overtime(): OvertimeService
{
    return app(OvertimeService::class);
}

function payroll(): PayrollService
{
    return app(PayrollService::class);
}

function statutory(): StatutoryCalculator
{
    return app(StatutoryCalculator::class);
}

/**
 * Import days for a contracted employee and, by default, have the site certify
 * them. Goes through the importer and the validation step rather than writing
 * DTR rows, so a fixture cannot produce a day the contract gate would refuse.
 *
 * @param  array<int, string>  $dates
 */
function workedDays(
    Employee $employee,
    Project $project,
    array $dates,
    bool $validate = true,
    string $in = '07:00',
    string $out = '16:00',
    int $break = 60,
): void {
    $rows = array_map(fn (string $date): array => [
        'employee_number' => $employee->employee_number,
        'work_date' => $date,
        'time_in' => $in,
        'time_out' => $out,
        'break_minutes' => $break,
    ], $dates);

    $result = timekeeping()->import($project, $rows);

    if ($result['rejected'] !== []) {
        throw new RuntimeException('Fixture import rejected rows: '.json_encode($result['rejected']));
    }

    if (! $validate) {
        return;
    }

    foreach ($dates as $date) {
        $record = DailyTimeRecord::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('work_date', $date)
            ->sole();

        timekeeping()->validate($record, User::factory()->create());
    }
}

function payrollVariance(): PayrollVarianceService
{
    return app(PayrollVarianceService::class);
}

/**
 * Open and compute a run for the employee's organization.
 */
function computedRun(Employee $employee, string $periodStart, string $periodEnd): PayrollRun
{
    return payroll()->compute(payroll()->open(
        $employee->organization()->sole(),
        Carbon::parse($periodStart),
        Carbon::parse($periodEnd),
    ));
}

function disbursement(): DisbursementService
{
    return app(DisbursementService::class);
}

function payslips(): PayslipService
{
    return app(PayslipService::class);
}

/**
 * A contracted employee inside a GIVEN organization.
 *
 * `contractedEmployee()` makes its own organization, which is right for suites
 * about one person and wrong for a payroll run — a run iterates an organization,
 * so two employees who must appear on one register have to share one.
 */
function contractedEmployeeIn(Organization $organization, string $number): Employee
{
    $employee = employees()->hire($organization, [
        'employee_number' => $number,
        'first_name' => 'Site',
        'last_name' => 'Worker',
        'date_hired' => '2026-04-01',
        'position' => 'Carpenter',
        'bank_account_number' => '001234567890',
    ]);

    $contract = hiring()->issueContract($employee, Carbon::parse('2026-04-01'), null, PayBasis::Daily, '1200.0000');
    hiring()->signContract($contract, Carbon::parse('2026-03-30'), 'M. Reyes');

    return $employee->fresh();
}

/**
 * Open, compute and approve a run — the state disbursement starts from.
 */
function approvedRun(Employee $employee, string $periodStart, string $periodEnd): PayrollRun
{
    return payroll()->approve(
        computedRun($employee, $periodStart, $periodEnd),
        User::factory()->create(),
    );
}

function laborPoster(): LaborCostPoster
{
    return app(LaborCostPoster::class);
}
