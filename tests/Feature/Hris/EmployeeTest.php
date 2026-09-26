<?php

use App\Domain\Hris\EmploymentStatus;
use App\Domain\Hris\PayBasis;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The employee 201 file — P3-01
|--------------------------------------------------------------------------
|
| PLAN.md §3: sensitive columns encrypted at rest **in the first migration that
| creates them**. This is the table that rule was written for. A vendor's bank
| details are commercially sensitive; an employee's SSS number, TIN and salary
| rate are personal, and the people they belong to did not choose to give them to
| a system — they gave them to an employer.
|
| "In the first migration" is the whole point and it is not fussiness. Adding
| encryption later means the plaintext is already in every backup, every replica
| and every database dump anyone has taken in the meantime, and no migration can
| reach those.
|
| The other half of this task is that **a rate is a history, not a column.** The
| question payroll asks is not "what does this person earn" but "what were they
| earning during the period I am computing" — and a single mutable `daily_rate`
| answers only for today, which silently restates every payroll run ever made
| against it.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('opens a 201 file for a new hire', function () {
    $employee = hiredEmployee(['employee_number' => 'EMP-0001']);

    expect($employee->employee_number)->toBe('EMP-0001')
        ->and($employee->status)->toBe(EmploymentStatus::Active);
});

it('encrypts every government number at rest', function () {
    // THE RULE, asserted against the raw column rather than the model. Reading
    // it back through the cast proves only that the cast works; reading the
    // column proves the database does not hold the plaintext.
    $employee = hiredEmployee();

    $raw = DB::table('employees')->where('id', $employee->getKey())->first();

    foreach (['sss_number', 'philhealth_number', 'pagibig_number', 'tin', 'bank_account_number'] as $column) {
        expect($raw->{$column})->not->toBeNull()
            ->and($raw->{$column})->not->toContain('5678');
    }

    // And the cast still returns the real value to the application.
    expect($employee->fresh()->sss_number)->toBe('34-5678901-2');
});

it('encrypts the salary rate at rest', function () {
    // A rate is as sensitive as a government number and more useful to anybody
    // who should not have it: it is the one field that tells a reader what
    // everyone in the company is worth relative to everyone else.
    $employee = hiredEmployee();
    employees()->setRate($employee, PayBasis::Daily, '1200.0000', Carbon::parse('2026-02-01'));

    $raw = DB::table('employee_rates')->where('employee_id', $employee->getKey())->first();

    expect($raw->rate)->not->toBe('1200.0000')
        ->and($raw->rate)->not->toContain('1200');
});

it('refuses two employees with the same number', function () {
    // The employee number is what a timekeeper writes on a DTR and what the
    // biometrics export keys on. Two people answering to it makes every hour
    // either of them works unattributable.
    //
    // A readable refusal from the service now, not a database error: the add
    // employee form puts this message beside the field. The unique index still
    // stands behind it — see the next test.
    hiredEmployee(['employee_number' => 'EMP-DUP']);

    expect(fn () => hiredEmployee(['employee_number' => 'EMP-DUP']))
        ->toThrow(DomainException::class, 'already');
});

it('still refuses a duplicate number at the database, for anything that bypasses the service', function () {
    // An importer or a script writing rows directly never calls hire(). The
    // unique index is what refuses those, and it must not be removed on the
    // strength of the service check above.
    hiredEmployee(['employee_number' => 'EMP-DUP-DB']);

    expect(fn () => Employee::factory()->create(['employee_number' => 'EMP-DUP-DB']))
        ->toThrow(QueryException::class);
});

it('keeps a rate history rather than a single mutable figure', function () {
    // THE OTHER RULE. Payroll does not ask what somebody earns; it asks what
    // they were earning during the period being computed. A mutable column
    // answers only for today, and silently restates every run made against it.
    $employee = hiredEmployee();

    employees()->setRate($employee, PayBasis::Daily, '1200.0000', Carbon::parse('2026-02-01'));
    employees()->setRate($employee->fresh(), PayBasis::Daily, '1350.0000', Carbon::parse('2026-05-01'));

    expect($employee->fresh()->rates()->count())->toBe(2);
});

it('reads the rate in force on a given date, not the latest one', function () {
    // A payroll run for April must use April's rate even when it is computed in
    // June — which is exactly when a late or corrected run happens.
    $employee = hiredEmployee();

    employees()->setRate($employee, PayBasis::Daily, '1200.0000', Carbon::parse('2026-02-01'));
    employees()->setRate($employee->fresh(), PayBasis::Daily, '1350.0000', Carbon::parse('2026-05-01'));

    expect(employees()->rateOn($employee->fresh(), Carbon::parse('2026-04-15'))?->rate)->toBe('1200.0000')
        ->and(employees()->rateOn($employee->fresh(), Carbon::parse('2026-05-15'))?->rate)->toBe('1350.0000');
});

it('treats a rate as effective on its own first day', function () {
    // The boundary. Somebody is hired, or gets a raise, on the first of the
    // month, and being wrong here underpays them for a day.
    $employee = hiredEmployee();
    employees()->setRate($employee, PayBasis::Daily, '1350.0000', Carbon::parse('2026-05-01'));

    expect(employees()->rateOn($employee->fresh(), Carbon::parse('2026-05-01'))?->rate)->toBe('1350.0000')
        ->and(employees()->rateOn($employee->fresh(), Carbon::parse('2026-04-30')))->toBeNull();
});

it('refuses a second rate effective the same day', function () {
    // Two rates on one day means payroll picks one by query order, and the
    // person is paid whichever the database returned first.
    $employee = hiredEmployee();
    employees()->setRate($employee, PayBasis::Daily, '1200.0000', Carbon::parse('2026-05-01'));

    expect(fn () => employees()->setRate($employee->fresh(), PayBasis::Daily, '1350.0000', Carbon::parse('2026-05-01')))
        ->toThrow(QueryException::class);
});

it('refuses a zero or negative rate', function () {
    $employee = hiredEmployee();

    expect(fn () => employees()->setRate($employee, PayBasis::Daily, '0.0000', Carbon::parse('2026-05-01')))
        ->toThrow(DomainException::class);
});

it('reports no rate at all before the first one takes effect', function () {
    // Absence is not zero. A payroll run that treated a missing rate as zero
    // would pay somebody nothing and look like it worked.
    $employee = hiredEmployee();

    expect(employees()->rateOn($employee, Carbon::parse('2026-05-01')))->toBeNull();
});

it('separates a resignation from a termination', function () {
    // Both end employment and they are not the same event. Final pay,
    // re-hireability and the reason an auditor will ask about all differ, and a
    // single `is_active` boolean records none of it.
    $resigned = hiredEmployee();
    $terminated = hiredEmployee();

    employees()->separate($resigned, EmploymentStatus::Resigned, Carbon::parse('2026-05-31'), 'Accepted a role overseas.', User::factory()->create());
    employees()->separate($terminated, EmploymentStatus::Terminated, Carbon::parse('2026-05-31'), 'Falsified time records.', User::factory()->create());

    expect($resigned->fresh()->status)->toBe(EmploymentStatus::Resigned)
        ->and($terminated->fresh()->status)->toBe(EmploymentStatus::Terminated);
});

it('refuses a separation with no reason', function () {
    $employee = hiredEmployee();

    expect(fn () => employees()->separate($employee, EmploymentStatus::Resigned, Carbon::parse('2026-05-31'), '  '))
        ->toThrow(DomainException::class);
});

it('refuses to separate somebody twice', function () {
    $employee = hiredEmployee();
    employees()->separate($employee, EmploymentStatus::Resigned, Carbon::parse('2026-05-31'), 'Moved away.');

    expect(fn () => employees()->separate($employee->fresh(), EmploymentStatus::Terminated, Carbon::parse('2026-06-30'), 'Changed our minds.'))
        ->toThrow(DomainException::class);
});

it('lists only active employees as payable', function () {
    // The list a payroll run iterates. Somebody who left in May must not appear
    // in June's run, and "active" is the only question that answers it.
    $organization = Organization::factory()->create();

    $active = employees()->hire($organization, [
        'employee_number' => 'EMP-ACTIVE', 'first_name' => 'A', 'last_name' => 'B', 'date_hired' => '2026-01-01',
    ]);

    $gone = employees()->hire($organization, [
        'employee_number' => 'EMP-GONE', 'first_name' => 'C', 'last_name' => 'D', 'date_hired' => '2026-01-01',
    ]);

    employees()->separate($gone, EmploymentStatus::Resigned, Carbon::parse('2026-04-30'), 'Left.');

    $payable = employees()->activeFor($organization)->pluck('employee_number')->all();

    expect($payable)->toContain('EMP-ACTIVE')
        ->and($payable)->not->toContain('EMP-GONE');
});
