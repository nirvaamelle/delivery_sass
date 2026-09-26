<?php

use App\Domain\Hris\AllowanceService;
use App\Domain\Hris\LeaveService;
use App\Domain\Hris\PayBasis;
use App\Domain\Support\Money;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\PayrollLine;
use App\Models\Project;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Benefits on the payroll register
|--------------------------------------------------------------------------
|
| PLACEHOLDER (DECISIONS-PENDING.md, unnumbered). What a run now does with them:
|
|   - a **taxable allowance** is added to gross, so contributions and withholding
|     are computed on it;
|   - a **non-taxable allowance** is added to net only;
|   - **service incentive leave** is paid at the day's daily rate, into gross,
|     once — the record is stamped with the line that paid it;
|   - leave with **no worked day in the cutoff is held**, and the register says
|     so, because labour cost is posted by the projects days were worked on and
|     a line with no days has nowhere to post;
|   - the **posting spreads leave pay and allowances across the projects worked**,
|     in proportion to the pay earned on each, so the ledger still matches the
|     register to the centavo.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-20 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

/**
 * An employee past twelve months of service, on 1,200 a day, with a signed
 * contract in force — so leave is available and days import.
 */
function tenuredEmployee(string $number): Employee
{
    $employee = hiredEmployee(['employee_number' => $number, 'date_hired' => '2025-03-01']);

    $contract = hiring()->issueContract($employee, Carbon::parse('2025-03-01'), null, PayBasis::Daily, '1200.0000');
    hiring()->signContract($contract, Carbon::parse('2025-02-27'), 'N. Cruz');

    return $employee->fresh();
}

function lineFor(Employee $employee): PayrollLine
{
    return PayrollLine::query()->where('employee_id', $employee->getKey())->latest('id')->firstOrFail();
}

it('adds a taxable allowance to gross and a non-taxable one to net', function () {
    $employee = contractedEmployee('EMP-BN-1');
    workedDays($employee, Project::factory()->create(), ['2026-05-04', '2026-05-05']);

    app(AllowanceService::class)->grant($employee, 'Site hazard', '1000.00', true, Carbon::parse('2026-05-01'));
    app(AllowanceService::class)->grant($employee, 'Rice', '500.00', false, Carbon::parse('2026-05-01'));

    $run = computedRun($employee, '2026-05-01', '2026-05-15');
    $line = lineFor($employee);

    $deductions = statutory()->breakdown('3400.0000');
    $net = bcadd(bcsub('3400.0000', $deductions['total'], Money::SCALE), '500.0000', Money::SCALE);

    expect((string) $line->basic_pay)->toBe('2400.0000')
        ->and((string) $line->taxable_allowances)->toBe('1000.0000')
        ->and((string) $line->non_taxable_allowances)->toBe('500.0000')
        ->and((string) $line->gross_pay)->toBe('3400.0000')
        ->and((string) $line->withholding_tax)->toBe($deductions['withholding'])
        ->and((string) $line->net_pay)->toBe($net)
        ->and((string) $run->net_total)->toBe($net);
});

it('pays service incentive leave at the daily rate, once', function () {
    $employee = tenuredEmployee('EMP-BN-2');
    $project = Project::factory()->create();
    workedDays($employee, $project, ['2026-05-04']);
    $record = app(LeaveService::class)->record($employee, Carbon::parse('2026-05-05'), '1', 'Family matter');

    computedRun($employee, '2026-05-01', '2026-05-15');
    $line = lineFor($employee);

    expect((string) $line->leave_pay)->toBe('1200.0000')
        ->and((string) $line->leave_days)->toBe('1.0')
        ->and((string) $line->gross_pay)->toBe('2400.0000')
        ->and($record->fresh()->payroll_line_id)->toBe($line->getKey());

    // The next cutoff does not pay it again.
    workedDays($employee, $project, ['2026-05-18']);
    computedRun($employee, '2026-05-16', '2026-05-31');

    expect((string) (lineFor($employee)->leave_pay ?? '0.0000'))->toBe('0.0000');
});

it('pays half a day of leave as half the daily rate', function () {
    $employee = tenuredEmployee('EMP-BN-3');
    workedDays($employee, Project::factory()->create(), ['2026-05-04']);
    app(LeaveService::class)->record($employee, Carbon::parse('2026-05-05'), '0.5');

    computedRun($employee, '2026-05-01', '2026-05-15');

    expect((string) lineFor($employee)->leave_pay)->toBe('600.0000');
});

it('holds leave when no day was worked in the cutoff, and says so on the register', function () {
    $employee = tenuredEmployee('EMP-BN-4');
    $record = app(LeaveService::class)->record($employee, Carbon::parse('2026-05-05'), '1');

    $run = computedRun($employee, '2026-05-01', '2026-05-15');

    expect(PayrollLine::query()->where('employee_id', $employee->getKey())->exists())->toBeFalse()
        ->and($record->fresh()->payroll_line_id)->toBeNull()
        ->and(collect($run->exceptions)->pluck('reason')->implode(' '))->toContain('leave');
});

it('spreads leave pay and allowances across the projects worked, matching the register exactly', function () {
    Carbon::setTestNow('2026-06-02 09:00:00');

    $employee = tenuredEmployee('EMP-BN-5');
    $north = Project::factory()->create(['organization_id' => $employee->organization_id]);
    $south = Project::factory()->create(['organization_id' => $employee->organization_id]);

    workedDays($employee, $north, ['2026-05-04']);
    workedDays($employee, $south, ['2026-05-06', '2026-05-07']);
    app(LeaveService::class)->record($employee, Carbon::parse('2026-05-05'), '1');
    app(AllowanceService::class)->grant($employee, 'Site hazard', '900.00', true, Carbon::parse('2026-05-01'));
    app(AllowanceService::class)->grant($employee, 'Rice', '300.00', false, Carbon::parse('2026-05-01'));

    $run = approvedRun($employee, '2026-05-01', '2026-05-15');
    $line = lineFor($employee);
    $byProject = laborPoster()->grossByProject($run);

    // Extras 1,200 leave + 900 + 300 = 2,400, split 1 : 2 by pay earned.
    expect($byProject[$north->getKey()])->toBe('2000.0000')
        ->and($byProject[$south->getKey()])->toBe('4000.0000')
        ->and(Money::sum(...array_values($byProject)))
        ->toBe(Money::sum((string) $line->gross_pay, (string) $line->non_taxable_allowances));
});

it('puts leave and allowances on the payslip', function () {
    $employee = tenuredEmployee('EMP-BN-6');
    workedDays($employee, Project::factory()->create(), ['2026-05-04']);
    app(LeaveService::class)->record($employee, Carbon::parse('2026-05-05'), '1');
    app(AllowanceService::class)->grant($employee, 'Rice', '300.00', false, Carbon::parse('2026-05-01'));

    computedRun($employee, '2026-05-01', '2026-05-15');
    $summary = payslips()->summaryFor(lineFor($employee));

    expect($summary['leave_pay'])->toBe('1200.0000')
        ->and($summary['leave_days'])->toBe('1.0')
        ->and($summary['taxable_allowances'])->toBe('0.0000')
        ->and($summary['non_taxable_allowances'])->toBe('300.0000');
});

it('renders a payslip PDF that carries leave and both kinds of allowance', function () {
    // A payslip is issued off an approved register only.
    Carbon::setTestNow('2026-06-02 09:00:00');

    $employee = tenuredEmployee('EMP-BN-8');
    workedDays($employee, Project::factory()->create(), ['2026-05-04']);
    app(LeaveService::class)->record($employee, Carbon::parse('2026-05-05'), '0.5');
    app(AllowanceService::class)->grant($employee, 'Site hazard', '400.00', true, Carbon::parse('2026-05-01'));
    app(AllowanceService::class)->grant($employee, 'Rice', '300.00', false, Carbon::parse('2026-05-01'));

    approvedRun($employee, '2026-05-01', '2026-05-15');

    $html = view('payslips.default', payslips()->summaryFor(lineFor($employee)))->render();

    expect($html)->toContain('Paid leave (0.5 days)')
        ->and($html)->toContain('Taxable allowances')
        ->and($html)->toContain('Non-taxable allowances')
        ->and(payslips()->render(lineFor($employee)))->not->toBeNull();
});

it('leaves a run with no benefits exactly as it was', function () {
    $employee = contractedEmployee('EMP-BN-7');
    workedDays($employee, Project::factory()->create(), ['2026-05-04']);

    computedRun($employee, '2026-05-01', '2026-05-15');
    $line = lineFor($employee);

    expect((string) $line->gross_pay)->toBe('1200.0000')
        ->and((string) $line->leave_pay)->toBe('0.0000')
        ->and(LeaveRecord::query()->count())->toBe(0);
});
