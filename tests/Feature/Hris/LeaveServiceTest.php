<?php

use App\Domain\Hris\EmployeeService;
use App\Domain\Hris\LeaveService;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Service Incentive Leave
|--------------------------------------------------------------------------
|
| PLACEHOLDER (DECISIONS-PENDING.md, unnumbered): five days a leave year after
| twelve months of service, the leave year running from the hiring anniversary,
| no carry-over and no cash conversion.
|
*/

function leave(): LeaveService
{
    return app(LeaveService::class);
}

function leaveEmployee(string $hired = '2025-03-10'): Employee
{
    return app(EmployeeService::class)->hire(Organization::factory()->create(), [
        'employee_number' => 'EMP-LV-'.uniqid(),
        'first_name' => 'Nena',
        'last_name' => 'Cruz',
        'date_hired' => $hired,
    ]);
}

it('is not entitled before twelve months of service', function () {
    $employee = leaveEmployee('2025-03-10');

    expect(leave()->balanceOn($employee, Carbon::parse('2026-03-09')))->toBe('0.0')
        ->and(leave()->balanceOn($employee, Carbon::parse('2026-03-10')))->toBe('5.0');

    expect(fn () => leave()->record($employee, Carbon::parse('2026-03-02'), '1'))
        ->toThrow(DomainException::class, 'not yet entitled');
});

it('runs the leave year from the hiring anniversary', function () {
    $year = leave()->leaveYearFor(leaveEmployee('2025-03-10'), Carbon::parse('2026-11-20'));

    expect($year['start']->toDateString())->toBe('2026-03-10')
        ->and($year['end']->toDateString())->toBe('2027-03-09');
});

it('records whole and half days against the balance', function () {
    $employee = leaveEmployee();

    leave()->record($employee, Carbon::parse('2026-04-06'), '1', 'Family matter');
    leave()->record($employee, Carbon::parse('2026-04-07'), '0.5');

    expect(leave()->balanceOn($employee, Carbon::parse('2026-05-01')))->toBe('3.5');
});

it('refuses more than the balance, and gives the days back in the next leave year', function () {
    $employee = leaveEmployee();

    foreach (['2026-04-06', '2026-04-07', '2026-04-08', '2026-04-09', '2026-04-10'] as $date) {
        leave()->record($employee, Carbon::parse($date), '1');
    }

    expect(fn () => leave()->record($employee, Carbon::parse('2026-04-13'), '0.5'))
        ->toThrow(DomainException::class, 'Only 0.0 days');

    // Not carried over: the next leave year starts at five again.
    expect(leave()->balanceOn($employee, Carbon::parse('2027-03-10')))->toBe('5.0');
});

it('refuses a duplicate date and a day outside employment', function () {
    $employee = leaveEmployee();
    leave()->record($employee, Carbon::parse('2026-04-06'), '1');

    expect(fn () => leave()->record($employee, Carbon::parse('2026-04-06'), '0.5'))->toThrow(DomainException::class, 'already recorded');
    expect(fn () => leave()->record($employee, Carbon::parse('2024-01-01'), '1'))->toThrow(DomainException::class, 'not employed');

});

it('refuses leave on a day the employee has worked', function () {
    // Through the real importer: a DTR needs a signed contract, and the
    // contracted fixture is not yet entitled — the worked-day refusal comes first.
    Carbon::setTestNow('2026-05-20 09:00:00');
    $employee = contractedEmployee('EMP-LV-WORKED');
    workedDays($employee, Project::factory()->create(), ['2026-05-04']);

    expect(fn () => leave()->record($employee, Carbon::parse('2026-05-04'), '1'))->toThrow(DomainException::class, 'never both');

    Carbon::setTestNow();
});

it('refuses a leave amount other than a whole or half day', function () {
    expect(fn () => leave()->record(leaveEmployee(), Carbon::parse('2026-04-06'), '2'))->toThrow(DomainException::class, 'whole day');
});

it('cancels unpaid leave with a reason, returning the day to the balance', function () {
    $employee = leaveEmployee();
    $record = leave()->record($employee, Carbon::parse('2026-04-06'), '1');

    expect(fn () => leave()->cancel($record, ' '))->toThrow(DomainException::class, 'reason');

    leave()->cancel($record, 'Reported for work after all.');

    expect(leave()->balanceOn($employee, Carbon::parse('2026-05-01')))->toBe('5.0')
        ->and(leave()->unpaidUpTo($employee, Carbon::parse('2026-04-15')))->toHaveCount(0);
});

it('refuses to cancel leave a payroll run has paid', function () {
    $record = leave()->record(leaveEmployee(), Carbon::parse('2026-04-06'), '1');

    // The refusal reads the paid marker before anything is saved, so the marker
    // is set in memory; the payroll integration test pays a real record.
    $record->payroll_line_id = 1;

    expect(fn () => leave()->cancel($record, 'Mistake'))
        ->toThrow(DomainException::class, 'already been paid');
});

it('lists unpaid leave up to a cutoff, carrying earlier held days', function () {
    $employee = leaveEmployee();
    leave()->record($employee, Carbon::parse('2026-03-20'), '1');
    leave()->record($employee, Carbon::parse('2026-04-06'), '1');
    leave()->record($employee, Carbon::parse('2026-04-20'), '1');

    expect(leave()->unpaidUpTo($employee, Carbon::parse('2026-04-15'))->pluck('leave_date')->map->toDateString()->all())
        ->toBe(['2026-03-20', '2026-04-06']);
});
