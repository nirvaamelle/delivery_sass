<?php

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Gates\GateFailedException;
use App\Domain\Hris\DisbursementMethod;
use App\Domain\Hris\DtrStatus;
use App\Domain\Hris\PayrollRunStatus;
use App\Domain\Posting\LedgerCategory;
use App\Models\CostCode;
use App\Models\CutoffCalendar;
use App\Models\DailyTimeRecord;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Phase 3 exit gate — P3-14
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Part C states it as behaviour:
|
|   "One semi-monthly cutoff runs on staging with an unvalidated day HELD and
|    PAID IN THE FOLLOWING CUTOFF. Labor cost appears in the ledger."
|
| The clause that carries the phase is the middle one, and it is a claim about
| TWO cutoffs. Holding a day is easy — any status column does it. What the gate
| asks is that the held day is **paid later**, at the rate that was in force when
| it was worked, flagged so the payslip can explain itself, and **never twice**.
|
| So the central test below walks both cutoffs in one test: May 1–15 pays one day
| and holds another; the site certifies the held day late; May 16–31 pays it and
| nothing else. Three assertions carry it — the second register pays the held
| day, the first register did not, and the day cannot appear on a third.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-06-02 09:00:00');
    Storage::fake('local');

    // Both halves of May: the gate spans two cutoffs, which is the whole point.
    foreach ([['2026-05-01', '2026-05-15'], ['2026-05-16', '2026-05-31']] as [$start, $end]) {
        CutoffCalendar::query()->create([
            'project_id' => null,
            'cutoff_type' => CutoffType::Payroll,
            'period_start' => $start,
            'period_end' => $end,
            'cutoff_at' => '2026-12-31 17:00:00',
        ]);
    }
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A project with a cost code, so labour has somewhere to post.
 */
function gateProject(): Project
{
    $project = Project::factory()->create();

    CostCode::factory()->create([
        'organization_id' => $project->organization_id,
        'code' => '05.00.000',
        'name' => 'Direct labour',
    ]);

    return $project;
}

it('runs one semi-monthly cutoff from timelog to payslip', function () {
    // CLAUSE 1. The whole chain, every document through the service that owns its
    // gates: contract signed before the shift, days imported and certified, the
    // register computed, approved and disbursed.
    $employee = contractedEmployee();
    $project = gateProject();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05', '2026-05-06']);

    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    expect($run->number)->toStartWith('PAY-2026-')
        ->and($run->status)->toBe(PayrollRunStatus::Approved)
        ->and((string) $run->gross_total)->toBe('3600.0000');

    $payslip = payslips()->render($run->lines()->sole());

    Storage::disk('local')->assertExists($payslip->file_path);
});

it('holds an unvalidated day and pays it in the FOLLOWING cutoff, only once', function () {
    // CLAUSE 2 — THE ONE THE PHASE IS ABOUT, and the full arc in one test.
    //
    // Holding a day is easy; any status column does it. The gate asks that the
    // held day is PAID LATER, which is a claim about a second register computed
    // in a different month.
    $employee = contractedEmployee();
    $project = gateProject();

    workedDays($employee, $project, ['2026-05-04'], validate: true);
    workedDays($employee, $project, ['2026-05-05'], validate: false);

    // First cutoff: one day paid, one held.
    $first = approvedRun($employee, '2026-05-01', '2026-05-15');

    expect((string) $first->gross_total)->toBe('1200.0000')
        ->and($first->lines()->sole()->carried_days)->toBe(0);

    $held = DailyTimeRecord::query()
        ->where('employee_id', $employee->getKey())
        ->whereDate('work_date', '2026-05-05')
        ->sole();

    // Held, not dropped — the day still exists, with its hours and its date.
    expect($held->status)->toBe(DtrStatus::Held)
        ->and($held->held_from_period_end->toDateString())->toBe('2026-05-15')
        ->and((string) $held->hours_worked)->toBe('8.00');

    // The site certifies it late, in the following period.
    timekeeping()->validate($held->fresh(), User::factory()->create());

    // Second cutoff: the held day is paid, and flagged as carried.
    $second = approvedRun($employee, '2026-05-16', '2026-05-31');
    $line = $second->lines()->sole();

    expect((string) $line->gross_pay)->toBe('1200.0000')
        ->and($line->carried_days)->toBe(1)
        ->and($line->days()->sole()->carried)->toBeTrue()
        ->and($line->days()->sole()->work_date->toDateString())->toBe('2026-05-05');

    // And never twice: the day is stamped paid against the cutoff that paid it,
    // and a third register finds nothing left to pay.
    expect($held->fresh()->status)->toBe(DtrStatus::Paid)
        ->and($held->fresh()->paid_in_period_end->toDateString())->toBe('2026-05-31')
        ->and(timekeeping()->payableFor($employee->fresh(), Carbon::parse('2026-06-01'), Carbon::parse('2026-06-15')))
        ->toHaveCount(0);
});

it('says on the payslip that a day was carried', function () {
    // The mechanic reaching the person it was built for. Without the label the
    // payslip shows a day from a month they were already paid for.
    $employee = contractedEmployee();
    $project = gateProject();

    workedDays($employee, $project, ['2026-05-04'], validate: true);
    workedDays($employee, $project, ['2026-05-05'], validate: false);

    approvedRun($employee, '2026-05-01', '2026-05-15');

    $held = DailyTimeRecord::query()
        ->where('employee_id', $employee->getKey())
        ->whereDate('work_date', '2026-05-05')
        ->sole();

    timekeeping()->validate($held, User::factory()->create());

    $second = approvedRun($employee, '2026-05-16', '2026-05-31');

    expect(payslips()->summaryFor($second->lines()->sole()))
        ->toHaveKey('carried_days', 1);
});

it('puts labour cost in the ledger', function () {
    // CLAUSE 3. PLAN.md §1's organising principle, reached by the third chain:
    // per project, at gross, on the payroll calendar.
    $employee = contractedEmployee();
    $project = gateProject();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $entries = laborPoster()->post($run);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->category)->toBe(LedgerCategory::Labor)
        ->and($entries[0]->amount)->toBe('2400.0000')
        ->and($entries[0]->project_code)->toBe($project->code);

    expect(ProjectCostLedgerEntry::query()
        ->where('document_number', $run->number)
        ->where('category', LedgerCategory::Labor)
        ->exists())->toBeTrue();
});

it('refuses to pay a shift worked before the contract was signed', function () {
    // Not one of the three clauses, and included deliberately. The gate could be
    // satisfied by a system that pays anybody who punches in, and slide 7's
    // flattest rule is that the contract is signed BEFORE the first shift.
    $employee = hiredEmployee(['employee_number' => 'EMP-GATE-NOCONTRACT']);
    $project = gateProject();

    $result = timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => '2026-05-04', 'time_in' => '07:00', 'time_out' => '16:00'],
    ]);

    expect($result['imported'])->toBe(0)
        ->and($result['rejected'])->toHaveCount(1);
});

it('refuses to approve a register whose variance is unexplained', function () {
    // F10, beyond the gate's own wording. A gate satisfied by a register nobody
    // reviewed is a gate that demonstrates the happy path only.
    $employee = contractedEmployee();
    $project = gateProject();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    approvedRun($employee, '2026-05-01', '2026-05-15');

    workedDays($employee, $project, ['2026-05-18', '2026-05-19', '2026-05-20']);
    $second = computedRun($employee, '2026-05-16', '2026-05-31');

    expect(fn () => payroll()->approve($second, User::factory()->create()))
        ->toThrow(GateFailedException::class);
});

it('does not release a cash payroll until every payment is signed for', function () {
    // Also beyond the wording. "One cutoff runs" could be read as ending at the
    // register, and slide 7 ends it at the money — with an acknowledgment for
    // the cash half.
    $organization = Organization::factory()->create();
    $project = gateProject();

    $first = contractedEmployeeIn($organization, 'EMP-GATE-1');
    $second = contractedEmployeeIn($organization, 'EMP-GATE-2');

    workedDays($first, $project, ['2026-05-04']);
    workedDays($second, $project, ['2026-05-04']);

    $run = payroll()->approve(
        payroll()->compute(payroll()->open($organization, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'))),
        User::factory()->create(),
    );

    $batch = disbursement()->prepare($run, DisbursementMethod::CashPayout, User::factory()->create());

    disbursement()->acknowledge($batch->items()->first(), 'First Signer', Carbon::parse('2026-06-03'), User::factory()->create());

    expect($run->fresh()->status)->toBe(PayrollRunStatus::Approved)
        ->and(disbursement()->outstandingFor($batch->fresh()))->toBe(1);

    disbursement()->acknowledge($batch->fresh()->items()->whereNull('released_at')->sole(), 'Second Signer', Carbon::parse('2026-06-03'), User::factory()->create());

    expect($run->fresh()->status)->toBe(PayrollRunStatus::Released);
});
