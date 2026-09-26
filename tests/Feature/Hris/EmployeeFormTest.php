<?php

use App\Domain\Hris\EmploymentStatus;
use App\Domain\Hris\PayBasis;
use App\Filament\Resources\Employees\EmployeesResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Adding and editing employees on screen
|--------------------------------------------------------------------------
|
| The employee register was read-only, like every document screen — but an
| employee is master data, not a transaction, and HR had no way to add one or
| correct a name except the database.
|
| Three rules the screen keeps from what was already built:
|
|   - **Government numbers, bank account and rates are never displayed**, on the
|     register or in the form. They are write-only fields: blank keeps what is
|     stored, a new value replaces it. PLAN.md §3 encrypts them, and a form that
|     decrypts them undoes that.
|   - **A pay rate is a dated history**, so it is set by its own action and never
|     overwritten — a raise in May must not restate April's payroll.
|   - **Separation is its own act with a required reason**, not a status dropdown.
|
| Everything saves through EmployeeService, so the form enforces exactly what
| the service does.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-06-02 09:00:00');
    actingAs(userWithRole('hr-manager'));
});

afterEach(fn () => Carbon::setTestNow());

function formEmployee(array $overrides = []): Employee
{
    return employees()->hire(Organization::query()->first() ?? Organization::factory()->create(), array_merge([
        'employee_number' => 'EMP-F-'.uniqid(),
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'date_hired' => '2026-01-15',
        'position' => 'Mason',
        'sss_number' => '34-5678901-2',
        'tin' => '123-456-789-000',
        'bank_account_number' => '001234567890',
    ], $overrides));
}

/*
|--------------------------------------------------------------------------
| Adding
|--------------------------------------------------------------------------
*/

it('opens the add-employee page for HR', function () {
    get(EmployeesResource::getUrl('create'))->assertSuccessful();
});

it('adds an employee from the form, with government numbers encrypted', function () {
    $organization = Organization::factory()->create();

    Livewire::test(CreateEmployee::class)
        ->fillForm([
            'organization_id' => $organization->getKey(),
            'employee_number' => 'EMP-NEW-001',
            'first_name' => 'Ana',
            'last_name' => 'Villanueva',
            'date_hired' => '2026-06-01',
            'position' => 'Timekeeper',
            'sss_number' => '34-1234567-8',
            'philhealth_number' => '12-345678901-2',
            'pagibig_number' => '1234-5678-9012',
            'tin' => '123-456-789',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $employee = Employee::query()->where('employee_number', 'EMP-NEW-001')->sole();
    $raw = (string) DB::table('employees')->where('id', $employee->getKey())->value('sss_number');

    expect($employee->fullName())->toBe('Ana Villanueva')
        ->and($employee->status)->toBe(EmploymentStatus::Active)
        ->and($employee->sss_number)->toBe('34-1234567-8')
        ->and($raw)->not->toContain('34-1234567-8');
});

it('records a starting rate as the first entry in the rate history', function () {
    Livewire::test(CreateEmployee::class)
        ->fillForm([
            'organization_id' => Organization::factory()->create()->getKey(),
            'employee_number' => 'EMP-RATE-001',
            'first_name' => 'Ana',
            'last_name' => 'Villanueva',
            'date_hired' => '2026-06-01',
            'pay_basis' => PayBasis::Daily->value,
            'starting_rate' => '850.0000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $employee = Employee::query()->where('employee_number', 'EMP-RATE-001')->sole();
    $rate = employees()->rateOn($employee, Carbon::parse('2026-06-01'));

    // Effective from the date hired, so the first day worked is paid.
    expect($rate)->not->toBeNull()
        ->and((string) $rate->rate)->toBe('850.0000')
        ->and($rate->effective_from->toDateString())->toBe('2026-06-01');
});

it('adds an employee with no rate yet, and the register says so', function () {
    Livewire::test(CreateEmployee::class)
        ->fillForm([
            'organization_id' => Organization::factory()->create()->getKey(),
            'employee_number' => 'EMP-NORATE-001',
            'first_name' => 'Ana',
            'last_name' => 'Villanueva',
            'date_hired' => '2026-06-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(employees()->rateOn(Employee::query()->where('employee_number', 'EMP-NORATE-001')->sole(), now()))->toBeNull();
});

it('requires the fields a payroll run needs', function () {
    Livewire::test(CreateEmployee::class)
        ->fillForm(['employee_number' => '', 'first_name' => '', 'last_name' => '', 'date_hired' => null])
        ->call('create')
        ->assertHasFormErrors(['employee_number' => 'required', 'first_name' => 'required', 'last_name' => 'required', 'date_hired' => 'required']);
});

it('refuses a duplicate employee number on the form', function () {
    $existing = formEmployee();

    Livewire::test(CreateEmployee::class)
        ->fillForm([
            'organization_id' => $existing->organization_id,
            'employee_number' => $existing->employee_number,
            'first_name' => 'Ana',
            'last_name' => 'Villanueva',
            'date_hired' => '2026-06-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['employee_number']);

    expect(Employee::query()->where('employee_number', $existing->employee_number)->count())->toBe(1);
});

it('refuses a malformed SSS number on the form', function () {
    Livewire::test(CreateEmployee::class)
        ->fillForm([
            'organization_id' => Organization::factory()->create()->getKey(),
            'employee_number' => 'EMP-BADSSS-001',
            'first_name' => 'Ana',
            'last_name' => 'Villanueva',
            'date_hired' => '2026-06-01',
            'sss_number' => '1234',
        ])
        ->call('create')
        ->assertHasFormErrors(['sss_number']);

    expect(Employee::query()->where('employee_number', 'EMP-BADSSS-001')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

it('opens the edit page for HR', function () {
    get(EmployeesResource::getUrl('edit', ['record' => formEmployee()]))->assertSuccessful();
});

it('edits the name and position', function () {
    $employee = formEmployee();

    Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->fillForm(['first_name' => 'Juanito', 'position' => 'Lead Mason'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($employee->fresh()->first_name)->toBe('Juanito')
        ->and($employee->fresh()->position)->toBe('Lead Mason');
});

it('never renders an existing government number or bank account on the edit page', function () {
    // THE ONE THAT MATTERS. The fields are write-only.
    $employee = formEmployee();

    get(EmployeesResource::getUrl('edit', ['record' => $employee]))
        ->assertSuccessful()
        ->assertDontSee('34-5678901-2')
        ->assertDontSee('123-456-789-000')
        ->assertDontSee('001234567890');
});

it('keeps the SSS number when it is left blank on edit', function () {
    $employee = formEmployee();

    Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->fillForm(['first_name' => 'Juanito'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($employee->fresh()->sss_number)->toBe('34-5678901-2');
});

it('replaces the SSS number when a new one is entered on edit', function () {
    $employee = formEmployee();

    Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->fillForm(['sss_number' => '33-9999999-9'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($employee->fresh()->sss_number)->toBe('33-9999999-9');
});

it('does not let the employee number be changed on edit', function () {
    // Timekeeping imports and payroll lines match on it.
    $employee = formEmployee();
    $number = $employee->employee_number;

    Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->assertFormFieldIsDisabled('employee_number')
        ->assertFormFieldIsDisabled('date_hired');

    expect($employee->fresh()->employee_number)->toBe($number);
});

/*
|--------------------------------------------------------------------------
| Rate and separation — their own acts
|--------------------------------------------------------------------------
*/

it('sets a new rate as a dated entry, keeping the old one for past payroll', function () {
    $employee = formEmployee();
    employees()->setRate($employee, PayBasis::Daily, '800.0000', Carbon::parse('2026-01-15'));

    Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->callAction('setRate', data: [
            'basis' => PayBasis::Daily->value,
            'rate' => '900.0000',
            'effective_from' => '2026-06-01',
            'remarks' => 'Annual increase.',
        ])
        ->assertHasNoActionErrors();

    // April is still paid at the old rate; June at the new one.
    expect((string) employees()->rateOn($employee, Carbon::parse('2026-04-15'))->rate)->toBe('800.0000')
        ->and((string) employees()->rateOn($employee, Carbon::parse('2026-06-15'))->rate)->toBe('900.0000');
});

it('refuses a zero rate from the action', function () {
    $employee = formEmployee();

    Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->callAction('setRate', data: ['basis' => PayBasis::Daily->value, 'rate' => '0', 'effective_from' => '2026-06-01'])
        ->assertHasActionErrors(['rate']);

    expect($employee->rates()->count())->toBe(0);
});

it('never renders the rate on the edit page', function () {
    $employee = formEmployee();
    employees()->setRate($employee, PayBasis::Daily, '1234.5600', Carbon::parse('2026-01-15'));

    get(EmployeesResource::getUrl('edit', ['record' => $employee]))
        ->assertSuccessful()
        ->assertDontSee('1234.56');
});

it('separates an employee with a reason from the action', function () {
    $employee = formEmployee();

    Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->callAction('separate', data: [
            'status' => EmploymentStatus::Resigned->value,
            'separated_on' => '2026-06-01',
            'reason' => 'Resigned for relocation.',
        ])
        ->assertHasNoActionErrors();

    expect($employee->fresh()->status)->toBe(EmploymentStatus::Resigned)
        ->and($employee->fresh()->separation_reason)->toBe('Resigned for relocation.');
});

it('refuses a separation with no reason from the action', function () {
    $employee = formEmployee();

    Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->callAction('separate', data: ['status' => EmploymentStatus::Resigned->value, 'separated_on' => '2026-06-01', 'reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($employee->fresh()->status)->toBe(EmploymentStatus::Active);
});

it('offers no delete, because a 201 file is history', function () {
    // Payroll lines, DTRs and contracts point at this row. Leaving is recorded
    // by separation, never by removal.
    $employee = formEmployee();

    Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

/*
|--------------------------------------------------------------------------
| Who can
|--------------------------------------------------------------------------
*/

it('refuses the add and edit pages to a role without payroll access', function () {
    $employee = formEmployee();

    actingAs(userWithRole('project-manager'));

    get(EmployeesResource::getUrl('create'))->assertForbidden();
    get(EmployeesResource::getUrl('edit', ['record' => $employee]))->assertForbidden();
});

it('lets finance add an employee too', function () {
    actingAs(userWithRole('finance-manager'));

    get(EmployeesResource::getUrl('create'))->assertSuccessful();
});
