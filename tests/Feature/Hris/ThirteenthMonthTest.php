<?php

use App\Domain\Hris\ThirteenthMonthService;
use App\Models\Project;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| 13th month pay
|--------------------------------------------------------------------------
|
| One twelfth of the basic pay actually paid in the year — read from approved
| and released registers, never from rates, and never from a computed register
| that can still change. The computation and the report; not the payout.
|
| PLACEHOLDER (DECISIONS-PENDING.md, unnumbered): the basis is basic pay plus
| paid leave; the tax-exempt ceiling is config.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-06-02 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

function thirteenth(): ThirteenthMonthService
{
    return app(ThirteenthMonthService::class);
}

it('is one twelfth of the basic pay on approved registers, ignoring a computed one', function () {
    $employee = contractedEmployee('EMP-13-1');
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    approvedRun($employee, '2026-05-01', '2026-05-15');

    // Computed, not approved: it can still change, so it does not count.
    workedDays($employee, $project, ['2026-05-18']);
    computedRun($employee, '2026-05-16', '2026-05-31');

    expect(thirteenth()->forEmployee($employee, 2026))->toBe([
        'basis' => '2400.0000',
        'amount' => '200.0000',
        'tax_exempt' => '200.0000',
        'taxable' => '0.0000',
        'lines' => 1,
    ]);
});

it('splits the amount at the tax-exempt ceiling', function () {
    config(['payroll.benefits.thirteenth_month.tax_exempt_ceiling' => '150.00']);

    $employee = contractedEmployee('EMP-13-2');
    workedDays($employee, Project::factory()->create(), ['2026-05-04', '2026-05-05']);
    approvedRun($employee, '2026-05-01', '2026-05-15');

    $computed = thirteenth()->forEmployee($employee, 2026);

    expect($computed['tax_exempt'])->toBe('150.0000')
        ->and($computed['taxable'])->toBe('50.0000');
});

it('counts nothing from another year', function () {
    $employee = contractedEmployee('EMP-13-3');
    workedDays($employee, Project::factory()->create(), ['2026-05-04']);
    approvedRun($employee, '2026-05-01', '2026-05-15');

    expect(thirteenth()->forEmployee($employee, 2025)['lines'])->toBe(0)
        ->and(thirteenth()->forEmployee($employee, 2025)['amount'])->toBe('0.0000');
});

it('lists everybody in the company paid in the year', function () {
    $employee = contractedEmployee('EMP-13-4');
    workedDays($employee, Project::factory()->create(), ['2026-05-04']);
    approvedRun($employee, '2026-05-01', '2026-05-15');

    $rows = thirteenth()->forOrganization($employee->organization()->sole(), 2026);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['employee_number'])->toBe('EMP-13-4')
        ->and($rows[0]['amount'])->toBe('100.0000');
});
