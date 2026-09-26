<?php

use App\Domain\Hris\AllowanceService;
use App\Domain\Hris\EmployeeService;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Recurring allowances
|--------------------------------------------------------------------------
|
| PLACEHOLDER (DECISIONS-PENDING.md, unnumbered): per cutoff, paid in full for
| any cutoff the allowance is in force on, taxable ones into gross and
| non-taxable ones into net. Dated like a rate, so a change is an end and a new
| grant rather than an edit.
|
*/

function allowances(): AllowanceService
{
    return app(AllowanceService::class);
}

function allowanceEmployee(): Employee
{
    return app(EmployeeService::class)->hire(Organization::factory()->create(), [
        'employee_number' => 'EMP-AL-'.uniqid(),
        'first_name' => 'Lito',
        'last_name' => 'Ramos',
        'date_hired' => '2026-01-05',
    ]);
}

it('grants an allowance with its amount encrypted at rest', function () {
    $employee = allowanceEmployee();

    $allowance = allowances()->grant($employee, 'Transportation', '750.00', false, Carbon::parse('2026-02-01'));
    $raw = (string) DB::table('employee_allowances')->where('id', $allowance->getKey())->value('amount');

    expect((string) $allowance->fresh()->amount)->toBe('750.0000')
        ->and($raw)->not->toContain('750');
});

it('refuses a blank name, a zero amount, or a start before hire', function (string $name, string $amount, string $from, string $message) {
    expect(fn () => allowances()->grant(allowanceEmployee(), $name, $amount, true, Carbon::parse($from)))
        ->toThrow(DomainException::class, $message);
})->with([
    'blank name' => ['  ', '100.00', '2026-02-01', 'name'],
    'zero amount' => ['Meal', '0', '2026-02-01', 'above zero'],
    'before hire' => ['Meal', '100.00', '2025-12-01', 'before the employee was hired'],
]);

it('refuses two overlapping allowances with the same name, and allows one after the first ends', function () {
    $employee = allowanceEmployee();
    $first = allowances()->grant($employee, 'Rice', '500.00', false, Carbon::parse('2026-02-01'));

    expect(fn () => allowances()->grant($employee, 'Rice', '600.00', false, Carbon::parse('2026-03-01')))
        ->toThrow(DomainException::class, 'already has');

    allowances()->end($first, Carbon::parse('2026-02-28'));

    expect(allowances()->grant($employee, 'Rice', '600.00', false, Carbon::parse('2026-03-01'))->exists)->toBeTrue();
});

it('totals a cutoff by tax treatment, counting any allowance in force on at least one day', function () {
    $employee = allowanceEmployee();
    allowances()->grant($employee, 'Transportation', '750.00', false, Carbon::parse('2026-02-01'));
    allowances()->grant($employee, 'Site hazard', '1000.00', true, Carbon::parse('2026-02-10'));
    $ended = allowances()->grant($employee, 'Communication', '300.00', true, Carbon::parse('2026-01-05'));
    allowances()->end($ended, Carbon::parse('2026-01-31'));

    // 1–15 February: transportation all cutoff, hazard from the 10th, communication ended.
    expect(allowances()->totalsFor($employee, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-15')))
        ->toBe(['taxable' => '1000.0000', 'non_taxable' => '750.0000']);

    // 16–31 January: communication was in force.
    expect(allowances()->totalsFor($employee, Carbon::parse('2026-01-16'), Carbon::parse('2026-01-31')))
        ->toBe(['taxable' => '300.0000', 'non_taxable' => '0.0000']);
});

it('refuses to end an allowance twice or before it started', function () {
    $allowance = allowances()->grant(allowanceEmployee(), 'Meal', '200.00', false, Carbon::parse('2026-03-01'));

    expect(fn () => allowances()->end($allowance, Carbon::parse('2026-02-01')))->toThrow(DomainException::class, 'before it started');

    allowances()->end($allowance, Carbon::parse('2026-03-31'));

    expect(fn () => allowances()->end($allowance->fresh(), Carbon::parse('2026-04-30')))->toThrow(DomainException::class, 'already ended');
});
