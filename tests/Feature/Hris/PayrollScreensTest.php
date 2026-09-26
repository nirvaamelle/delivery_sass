<?php

use App\Domain\Hris\DisbursementMethod;
use App\Domain\Hris\OvertimeType;
use App\Filament\Resources\DailyTimeRecords\DailyTimeRecordsResource;
use App\Filament\Resources\DisbursementBatches\DisbursementBatchesResource;
use App\Filament\Resources\Employees\EmployeesResource;
use App\Filament\Resources\OvertimeAuthorities\OvertimeAuthoritiesResource;
use App\Filament\Resources\PayrollRuns\PayrollRunsResource;
use App\Models\DailyTimeRecord;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The Phase 3 screens — P3-12
|--------------------------------------------------------------------------
|
| Five screens over the payroll chain, and this is the chain where a screen
| leaking is worst. Phase 3 has encrypted government numbers, salary rates and
| every per-person pay figure at rest; a register that decrypts and prints them
| hands back exactly what the encryption was for, on a panel every foreman can
| open.
|
| So the tests that matter here are the ones asserting what is NOT rendered. The
| console gate proves the pages load clean; these prove they do not say too much.
|
| Read-only, like every document screen in this build — and with more weight than
| usual. A payroll figure typed into a form is a figure with no DTR, no rate
| history and no variance review behind it, and it would reach a bank account
| looking exactly like a computed one.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-06-02 09:00:00');
    // Screens are authorised by role (config/access.php). This suite tests what
    // the screens show, so it signs in as the administrator, who sees them all.
    actingAs(panelUser());
});

afterEach(fn () => Carbon::setTestNow());

it('renders every Phase 3 screen', function (string $resource) {
    get($resource::getUrl('index'))->assertSuccessful();
})->with([
    EmployeesResource::class,
    DailyTimeRecordsResource::class,
    OvertimeAuthoritiesResource::class,
    PayrollRunsResource::class,
    DisbursementBatchesResource::class,
]);

it('offers no way to create a payroll document from the screen', function (string $resource) {
    expect($resource::canCreate())->toBeFalse()
        ->and(array_key_exists('create', $resource::getPages()))->toBeFalse();
})->with([
    // EmployeesResource is deliberately NOT here any more. An employee is master
    // data, not a payroll document: HR adds and corrects people through a form
    // that saves via EmployeeService (EmployeeFormTest). DTRs, OT, runs and
    // disbursements stay read-only — each is the output of a checked process.
    DailyTimeRecordsResource::class,
    OvertimeAuthoritiesResource::class,
    PayrollRunsResource::class,
    DisbursementBatchesResource::class,
]);

it('never prints a government number or a rate on the employee register', function () {
    // THE ONE THAT MATTERS. PLAN.md §3 encrypts these at rest; a screen that
    // decrypts and shows them undoes that in the easiest place to read.
    $employee = contractedEmployee();

    get(EmployeesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($employee->employee_number)
        ->assertDontSee('34-5678901-2')      // SSS
        ->assertDontSee('123-456-789-000')   // TIN
        ->assertDontSee('001234567890')      // bank account
        ->assertDontSee('1200.0000');        // daily rate
});

it('says whether a rate is on file without showing what it is', function () {
    // The state a payroll clerk needs — a run refuses an employee with no rate,
    // and this is the column that says why somebody fell off a register.
    $withRate = contractedEmployee();
    $withoutRate = hiredEmployee(['employee_number' => 'EMP-NORATE-SCREEN']);

    get(EmployeesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($withRate->employee_number)
        ->assertSee($withoutRate->employee_number)
        ->assertDontSee('1200.0000');
});

it('shows a held day as held on the DTR screen', function () {
    // The held-day mechanic, visible. A day nobody can see is a day nobody
    // certifies, and the person who worked it stays unpaid.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04'], validate: false);
    timekeeping()->closeCutoff($employee, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'));

    // Asserted on the rendered date rather than the ISO one: the column formats
    // it, and a test that assumes the storage format passes only by accident.
    get(DailyTimeRecordsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Held')
        ->assertSee(Carbon::parse('2026-05-04')->format('M j, Y'));
});

it('shows totals on the payroll register but never a per-employee figure', function () {
    // A run total across everybody reveals nobody's pay, which is why
    // payroll_runs keeps its totals in the clear while every line is encrypted.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    // With exactly one employee the total and the line are the same number, so
    // a second worker is what makes the assertion mean anything.
    $second = contractedEmployeeIn($employee->organization()->sole(), 'EMP-SCREEN-2');
    workedDays($second, $project, ['2026-05-06']);

    get(PayrollRunsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($run->number);

    // The individual line amounts are not on the list.
    expect($run->lines()->count())->toBe(1);
});

it('shows what a payroll run still has unexplained under F10', function () {
    // A register that cannot be approved and does not say why is one somebody
    // reports as broken.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    workedDays($employee, $project, ['2026-05-18', '2026-05-19', '2026-05-20']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    expect(payrollVariance()->unexplained($second))->toHaveCount(1);

    get(PayrollRunsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($second->number);
});

it('shows what a disbursement batch is still waiting on', function () {
    // The working number on the treasurer's screen: how many payments have not
    // been signed for. A batch total alone makes a half-paid batch look settled.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::CashPayout, User::factory()->create());

    get(DisbursementBatchesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($batch->number);

    expect(disbursement()->outstandingFor($batch->fresh()))->toBe(1);
});

it('shows the payable hours an overtime authority actually buys', function () {
    // Authorised four, worked none past eight: the screen says 0 payable. A
    // screen showing only the authorised figure would suggest four are owed.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04'], out: '16:00');

    $authority = overtime()->request(
        $employee,
        $project,
        Carbon::parse('2026-05-04'),
        OvertimeType::Overtime,
        '4.00',
        'Planned pour, cancelled.',
        User::factory()->create(),
    );

    overtime()->approve($authority, User::factory()->create(), 'SE memo 2026-05-04-09');

    get(OvertimeAuthoritiesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('SE memo 2026-05-04-09');

    $day = DailyTimeRecord::query()
        ->where('employee_id', $employee->getKey())
        ->sole();

    expect(overtime()->payableOvertimeHours($day))->toBe('0.00');
});
