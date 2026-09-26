<?php

use App\Domain\Hris\AllowanceService;
use App\Domain\Hris\LeaveService;
use App\Filament\Pages\ThirteenthMonthPay;
use App\Filament\Resources\Employees\EmployeesResource;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\RelationManagers\AllowancesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\LeaveRecordsRelationManager;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\LeaveRecord;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Benefits on screen
|--------------------------------------------------------------------------
|
| Allowances and leave are managed on the employee's 201 file; 13th month pay
| has its own report under Payroll. Every action saves through its service, and
| an allowance amount is never displayed — the rule the rate already follows.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-06-02 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

function tenuredOnScreen(): Employee
{
    return employees()->hire(Organization::factory()->create(), [
        'employee_number' => 'EMP-SCR-'.uniqid(),
        'first_name' => 'Aida',
        'last_name' => 'Santos',
        'date_hired' => '2025-01-06',
    ]);
}

it('grants and ends an allowance from the employee page', function () {
    actingAs(userWithRole('hr-manager'));
    $employee = tenuredOnScreen();

    Livewire::test(AllowancesRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->callTableAction('grantAllowance', data: [
            'name' => 'Transportation',
            'amount' => '875.25',
            'taxable' => false,
            'effective_from' => '2026-06-01',
        ]);

    $allowance = EmployeeAllowance::query()->where('employee_id', $employee->getKey())->sole();

    expect((string) $allowance->amount)->toBe('875.2500')
        ->and($allowance->taxable)->toBeFalse();

    Livewire::test(AllowancesRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->callTableAction('endAllowance', $allowance, data: ['effective_to' => '2026-06-30']);

    expect($allowance->fresh()->effective_to->toDateString())->toBe('2026-06-30');
});

it('never shows an allowance amount on the employee page', function () {
    actingAs(userWithRole('hr-manager'));
    $employee = tenuredOnScreen();
    app(AllowanceService::class)->grant($employee, 'Transportation', '875.25', false, Carbon::parse('2026-06-01'));

    Livewire::test(AllowancesRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->assertSee('Transportation')
        ->assertDontSee('875.25')
        ->assertDontSee('875.2500');
});

it('records and cancels leave from the employee page, showing the balance', function () {
    actingAs(userWithRole('hr-manager'));
    $employee = tenuredOnScreen();

    Livewire::test(LeaveRecordsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->assertSee('Balance today: 5.0 days')
        ->callTableAction('recordLeave', data: ['leave_date' => '2026-06-03', 'days' => '1', 'reason' => 'Medical']);

    $record = LeaveRecord::query()->where('employee_id', $employee->getKey())->sole();

    Livewire::test(LeaveRecordsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->assertSee('Balance today: 4.0 days')
        ->callTableAction('cancelLeave', $record, data: ['reason' => 'Reported for work.']);

    expect($record->fresh()->cancelled_at)->not->toBeNull()
        ->and(app(LeaveService::class)->balanceOn($employee, now()))->toBe('5.0');
});

it('shows a refusal as a message, not an error page', function () {
    actingAs(userWithRole('hr-manager'));
    $employee = employees()->hire(Organization::factory()->create(), [
        'employee_number' => 'EMP-SCR-NEW',
        'first_name' => 'New',
        'last_name' => 'Hire',
        'date_hired' => '2026-05-01',
    ]);

    Livewire::test(LeaveRecordsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->callTableAction('recordLeave', data: ['leave_date' => '2026-06-03', 'days' => '1'])
        ->assertNotified();

    expect(LeaveRecord::query()->count())->toBe(0);
});

it('opens the employee page with both benefit tables for HR', function () {
    actingAs(userWithRole('hr-manager'));
    $employee = tenuredOnScreen();

    get(EmployeesResource::getUrl('edit', ['record' => $employee]))->assertSuccessful();
});

it('gives the 13th month report to HR and finance, and not to a project manager', function (string $role, int $status) {
    actingAs(userWithRole($role));

    get(ThirteenthMonthPay::getUrl())->assertStatus($status);
})->with([
    'hr' => ['hr-manager', 200],
    'finance' => ['finance-manager', 200],
    'project manager' => ['project-manager', 403],
]);

it('lists an employee paid on an approved register in the 13th month report', function () {
    actingAs(userWithRole('finance-manager'));

    $employee = contractedEmployee('EMP-SCR-13');
    workedDays($employee, Project::factory()->create(), ['2026-05-04']);
    approvedRun($employee, '2026-05-01', '2026-05-15');

    Livewire::test(ThirteenthMonthPay::class)
        ->set('year', 2026)
        ->assertSee('EMP-SCR-13')
        ->assertSee('100.00');
});
