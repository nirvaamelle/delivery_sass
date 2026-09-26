<?php

use App\Domain\Hris\EmploymentStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Editing the 201 file
|--------------------------------------------------------------------------
|
| Employees are master data, not transactions. Correcting a name or an SSS number
| is ordinary HR work, and until now there was no way to do it except the
| database. `EmployeeService::updateDetails()` is the one door, and the form goes
| through it.
|
| **An allow-list, not "everything fillable".** The employee number is what
| timekeeping imports and payroll lines match on; the date hired is what the
| contract gate reads; the organization decides whose payroll a person is on;
| status and separation have their own act with a required reason. None of those
| is a detail somebody corrects in passing, so the service refuses them here.
|
| **Government numbers are checked by digit count.** A form's main value is
| catching a typo before it reaches a remittance file: SSS 10 digits, PhilHealth
| 12, Pag-IBIG 12, TIN 9 or 12. Dashes and spaces are allowed, and the value is
| stored as entered.
|
| **Blank means unchanged.** The form never shows an existing government number,
| so leaving the field empty must keep it rather than erase it.
|
*/

function detailsEmployee(array $overrides = []): Employee
{
    return employees()->hire(Organization::factory()->create(), array_merge([
        'employee_number' => 'EMP-D-'.uniqid(),
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'date_hired' => '2026-01-15',
        'position' => 'Carpenter',
        'sss_number' => '34-5678901-2',
        'philhealth_number' => '12-345678901-2',
        'pagibig_number' => '1234-5678-9012',
        'tin' => '123-456-789-000',
        'bank_account_number' => '001234567890',
    ], $overrides));
}

/*
|--------------------------------------------------------------------------
| Hiring
|--------------------------------------------------------------------------
*/

it('refuses to hire without the fields a payroll run needs', function (string $field) {
    $attributes = [
        'employee_number' => 'EMP-REQ-'.uniqid(),
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'date_hired' => '2026-01-15',
    ];
    unset($attributes[$field]);

    expect(fn () => employees()->hire(Organization::factory()->create(), $attributes))
        ->toThrow(DomainException::class, $field);
})->with(['employee_number', 'first_name', 'last_name', 'date_hired']);

it('refuses a duplicate employee number with a readable message, not a database error', function () {
    // The unique index already refuses it — as a 500 on screen. Timekeeping
    // imports match on this number, so two people sharing it is two people's
    // hours on one payslip.
    $existing = detailsEmployee();

    expect(fn () => detailsEmployee(['employee_number' => $existing->employee_number]))
        ->toThrow(DomainException::class, 'already');
});

it('refuses a malformed government number at hire', function () {
    expect(fn () => detailsEmployee(['sss_number' => '1234']))
        ->toThrow(DomainException::class, 'SSS');
});

/*
|--------------------------------------------------------------------------
| Updating
|--------------------------------------------------------------------------
*/

it('updates the details HR corrects', function () {
    $employee = detailsEmployee();

    $updated = employees()->updateDetails($employee, [
        'first_name' => 'Maria Luisa',
        'last_name' => 'Santos-Reyes',
        'position' => 'Lead Carpenter',
        'department' => 'Structural',
        'contact_number' => '0917 555 0101',
    ], User::factory()->create());

    expect($updated->first_name)->toBe('Maria Luisa')
        ->and($updated->last_name)->toBe('Santos-Reyes')
        ->and($updated->position)->toBe('Lead Carpenter')
        ->and($updated->contact_number)->toBe('0917 555 0101');
});

it('replaces a government number and keeps it encrypted at rest', function () {
    $employee = detailsEmployee();

    employees()->updateDetails($employee, ['sss_number' => '33-1111111-1'], User::factory()->create());

    $raw = (string) DB::table('employees')->where('id', $employee->getKey())->value('sss_number');

    expect($employee->fresh()->sss_number)->toBe('33-1111111-1')
        ->and($raw)->not->toContain('33-1111111-1');
});

it('keeps a government number when the field is left blank', function (mixed $blank) {
    // The form never shows the existing value, so an empty field means "not
    // changing this", never "erase it".
    $employee = detailsEmployee();

    employees()->updateDetails($employee, ['sss_number' => $blank, 'first_name' => 'Maria'], User::factory()->create());

    expect($employee->fresh()->sss_number)->toBe('34-5678901-2');
})->with([null, '', '   ']);

it('refuses a malformed government number on update, naming which one', function (string $field, string $bad, string $label) {
    $employee = detailsEmployee();

    expect(fn () => employees()->updateDetails($employee, [$field => $bad], User::factory()->create()))
        ->toThrow(DomainException::class, $label);
})->with([
    'SSS too short' => ['sss_number', '12-345', 'SSS'],
    'PhilHealth too long' => ['philhealth_number', '12-3456789012345-6', 'PhilHealth'],
    'Pag-IBIG with letters' => ['pagibig_number', '1234-ABCD-9012', 'Pag-IBIG'],
    'TIN wrong length' => ['tin', '123-456-78', 'TIN'],
]);

it('accepts a 9-digit TIN as well as a 12-digit one', function () {
    $employee = detailsEmployee();

    employees()->updateDetails($employee, ['tin' => '123-456-789'], User::factory()->create());

    expect($employee->fresh()->tin)->toBe('123-456-789');
});

it('refuses to change what is not a detail', function (string $field, mixed $value) {
    // Each has its own act, or cannot change at all without breaking history.
    $employee = detailsEmployee();

    expect(fn () => employees()->updateDetails($employee, [$field => $value], User::factory()->create()))
        ->toThrow(DomainException::class, $field);
})->with([
    'employee number' => ['employee_number', 'EMP-CHANGED'],
    'date hired' => ['date_hired', '2020-01-01'],
    'organization' => ['organization_id', 999],
    'status' => ['status', EmploymentStatus::Terminated],
    'separation' => ['separated_on', '2026-06-01'],
]);

it('still lets HR correct the details of somebody who has left', function () {
    // Final pay, certificates of employment and disputes all read a leaver's
    // file. A typo in it does not stop mattering when they resign.
    $employee = detailsEmployee();
    employees()->separate($employee, EmploymentStatus::Resigned, now(), 'Resigned for relocation.', User::factory()->create());

    employees()->updateDetails($employee->fresh(), ['last_name' => 'Santos-Cruz'], User::factory()->create());

    expect($employee->fresh()->last_name)->toBe('Santos-Cruz');
});

it('refuses a blank name on update', function () {
    $employee = detailsEmployee();

    expect(fn () => employees()->updateDetails($employee, ['last_name' => '  '], User::factory()->create()))
        ->toThrow(DomainException::class, 'last_name');
});
