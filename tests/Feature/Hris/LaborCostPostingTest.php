<?php

use App\Domain\Cutoffs\CutoffClosedException;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\LedgerCategory;
use App\Models\CostCode;
use App\Models\CutoffCalendar;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Labour cost reaches the ledger — P3-11
|--------------------------------------------------------------------------
|
| PLAN.md §1: every process ends in `project_cost_ledger`. Phase 3's exit gate
| says it plainly — "labor cost appears in the ledger" — and the payroll chain
| has stopped one document short of it until now.
|
| Three decisions, each of which the obvious implementation gets wrong:
|
|   - **posted per PROJECT, not per employee.** A ledger row per person would put
|     every salary in the P&L, readable by anybody who can read the ledger. The
|     ledger is a cost record, not a payroll register, and it needs cost by
|     project — which is exactly what `payroll_line_days` already carries;
|   - **at GROSS, not net.** Labour cost to the project is what the work cost the
|     company, not what the employee took home. Posting net would understate every
|     project by the statutory deductions and leave them accounted nowhere;
|   - **on the PAYROLL calendar** (F3). Payroll closes semi-monthly, billing
|     monthly, OPEX on day 26. Posting labour against the billing calendar would
|     judge a payroll run by the wrong cutoff entirely.
|
| And it posts from an APPROVED register. A computed one can still be changed by
| the variance review, and the ledger is append-only — a posting made from it
| would need a reversing entry rather than an edit.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-06-02 09:00:00');

    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Payroll,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-15',
        'cutoff_at' => '2026-12-31 17:00:00',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A project with a cost code in its own organization.
 *
 * PLAN.md §1 wants every ledger row to carry a cost code, so a project with none
 * cannot be posted against at all — which is a real refusal the poster makes,
 * not a fixture inconvenience. The billing fixtures do the same for revenue.
 */
function costedProject(): Project
{
    $project = Project::factory()->create();

    CostCode::factory()->create([
        'organization_id' => $project->organization_id,
        'code' => '05.00.000',
        'name' => 'Direct labour',
    ]);

    return $project;
}

it('posts labour cost to the ledger when the register is approved', function () {
    // The exit gate's last clause, and the payroll chain's first ledger row.
    $employee = contractedEmployee();
    $project = costedProject();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $entries = laborPoster()->post($run);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->category)->toBe(LedgerCategory::Labor)
        ->and($entries[0]->amount)->toBe('2400.0000')
        ->and($entries[0]->project_code)->toBe($project->code)
        ->and($entries[0]->document_number)->toBe($run->number);
});

it('posts at gross, not at net', function () {
    // What the work cost the company, not what the employee took home. Posting
    // net would understate the project by the statutory deductions and leave
    // them accounted nowhere at all.
    $employee = contractedEmployee();
    $project = costedProject();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $entries = laborPoster()->post($run);

    expect((string) $run->fresh()->gross_total)->toBe('2400.0000')
        ->and((string) $run->fresh()->net_total)->toBe('2102.0000')
        ->and($entries[0]->amount)->toBe('2400.0000');
});

it('posts one row per project, never one per employee', function () {
    // THE ONE THAT MATTERS for who can read what. A row per person puts every
    // salary in the P&L. Two employees on one project is ONE ledger row, and
    // their individual pay is not recoverable from it.
    $organization = Organization::factory()->create();
    $project = costedProject();

    $first = contractedEmployeeIn($organization, 'EMP-LC-1');
    $second = contractedEmployeeIn($organization, 'EMP-LC-2');

    workedDays($first, $project, ['2026-05-04', '2026-05-05']);
    workedDays($second, $project, ['2026-05-04']);

    $run = payroll()->approve(
        payroll()->compute(payroll()->open($organization, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'))),
        User::factory()->create(),
    );

    $entries = laborPoster()->post($run);

    expect($entries)->toHaveCount(1)
        // 2,400 + 1,200, with neither figure separable from the row.
        ->and($entries[0]->amount)->toBe('3600.0000');
});

it('splits one employee across the projects they actually worked on', function () {
    // A carpenter on two sites in one cutoff is two ledger rows, each carrying
    // only the days worked there. Charging the whole cutoff to one project is
    // how a project's cost per unit becomes fiction.
    $employee = contractedEmployee();
    $projectA = costedProject();
    $projectB = costedProject();

    workedDays($employee, $projectA, ['2026-05-04', '2026-05-05']);
    workedDays($employee, $projectB, ['2026-05-06']);

    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $entries = collect(laborPoster()->post($run))->keyBy('project_code');

    expect($entries)->toHaveCount(2)
        ->and($entries[$projectA->code]->amount)->toBe('2400.0000')
        ->and($entries[$projectB->code]->amount)->toBe('1200.0000');
});

it('refuses to post a register that has not been approved', function () {
    // The variance review can still change a computed register, and the ledger
    // is append-only — a posting made from one would need a reversing entry
    // rather than an edit.
    $employee = contractedEmployee();
    $project = costedProject();

    workedDays($employee, $project, ['2026-05-04']);
    $run = computedRun($employee, '2026-05-01', '2026-05-15');

    expect(fn () => laborPoster()->post($run))
        ->toThrow(DomainException::class);
});

it('refuses to post the same register twice', function () {
    // A second posting doubles labour cost on every project in the run, and both
    // sets of rows look entirely ordinary.
    $employee = contractedEmployee();
    $project = costedProject();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    laborPoster()->post($run);

    expect(fn () => laborPoster()->post($run->fresh()))
        ->toThrow(DomainException::class);
});

it('posts against the payroll calendar, not the billing one', function () {
    // F3. Payroll closes semi-monthly, billing monthly, OPEX on day 26. Judging
    // a payroll run by the billing cutoff is judging it by the wrong date
    // entirely — this asserts it by closing ONLY the payroll calendar.
    CutoffCalendar::query()->where('cutoff_type', CutoffType::Payroll)->update([
        'cutoff_at' => '2026-05-16 17:00:00',
    ]);

    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Billing,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'cutoff_at' => '2026-12-31 17:00:00',
    ]);

    $employee = contractedEmployee();
    $project = costedProject();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    expect(fn () => laborPoster()->post($run))
        ->toThrow(CutoffClosedException::class);
});

it('leaves the register unposted when the ledger refuses it', function () {
    // The same discipline as the material and revenue posters: the stamp is
    // written inside the posting transaction, so a refused posting cannot leave
    // a register claiming to be in a ledger that never took it.
    CutoffCalendar::query()->where('cutoff_type', CutoffType::Payroll)->update([
        'cutoff_at' => '2026-05-16 17:00:00',
    ]);

    $employee = contractedEmployee();
    $project = costedProject();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    try {
        laborPoster()->post($run);
    } catch (CutoffClosedException) {
        // expected
    }

    expect($run->fresh()->posted_at)->toBeNull()
        ->and(ProjectCostLedgerEntry::query()->where('document_number', $run->number)->exists())->toBeFalse();
});

it('carries the cost code the days were worked against', function () {
    // PLAN.md §1 wants every ledger row to carry a cost code. Labour takes the
    // project's, since a DTR records where somebody worked rather than which
    // part of the works their hours belong to.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    $costCode = CostCode::factory()->create([
        'organization_id' => $project->organization_id,
        'code' => '05.10.100',
    ]);

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $entries = laborPoster()->post($run, $costCode);

    expect($entries[0]->cost_code)->toBe('05.10.100');
});

it('puts the labour row in the ledger the P and L is assembled from', function () {
    $employee = contractedEmployee();
    $project = costedProject();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    laborPoster()->post($run);

    expect(ProjectCostLedgerEntry::query()
        ->where('document_number', $run->number)
        ->where('category', LedgerCategory::Labor)
        ->exists())->toBeTrue();
});
