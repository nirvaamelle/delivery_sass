<?php

use App\Domain\Hris\DisbursementMethod;
use App\Domain\Hris\PayBasis;
use App\Domain\Hris\PayrollRunStatus;
use App\Models\DailyTimeRecord;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Payslips and the disbursement file — P3-10
|--------------------------------------------------------------------------
|
| Slide 7's step 8: "Disbursement by bank upload OR cash payout with
| acknowledgment."
|
| The "or" is the task. Two disbursement methods, and they prove payment in
| completely different ways:
|
|   - a **bank upload** is proven by the file that went to the bank and the
|     reference that came back. Nobody signs anything;
|   - a **cash payout** is proven by a signature, and by nothing else. Slide 7
|     says "with acknowledgment" precisely because cash without one is
|     indistinguishable from cash that never left the drawer.
|
| So the rule this file pins is that **a cash payment is not released until it is
| acknowledged**, while a bank payment is released by the file. Collapsing both
| into one `paid` boolean would lose the only evidence the cash half has.
|
| And the register must be APPROVED before either happens. F10's variance gate
| sits between computed and approved, so disbursing a computed register would
| walk straight past it.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-06-02 09:00:00');
    Storage::fake('local');
});

afterEach(fn () => Carbon::setTestNow());

it('refuses to disburse a register that is only computed', function () {
    // F10's gate sits between computed and approved. Disbursing a computed
    // register would pay everybody without the variance review ever happening.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = computedRun($employee, '2026-05-01', '2026-05-15');

    expect($run->status)->toBe(PayrollRunStatus::Computed)
        ->and(fn () => disbursement()->prepare($run, DisbursementMethod::BankUpload, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('prepares a bank upload file from an approved register', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::BankUpload, User::factory()->create());

    // The batch totals NET pay, not gross. Two days at 1,200 is 2,400 gross;
    // contributions of 298 come off (the SSS and PhilHealth floors bite at this
    // level) and there is no tax below the first bracket, so 2,102 is what
    // actually leaves the account.
    expect($batch->number)->toStartWith('DSB-2026-')
        ->and($batch->method)->toBe(DisbursementMethod::BankUpload)
        ->and($batch->items()->count())->toBe(1)
        ->and((string) $batch->total_amount)->toBe('2102.0000')
        ->and((string) $run->lines()->sole()->gross_pay)->toBe('2400.0000');
});

it('writes the bank file to private storage, never public', function () {
    // A disbursement file is every employee's name, bank account and net pay in
    // one document. On the public disk it is one guessed URL away from being
    // everybody's payslip.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::BankUpload, User::factory()->create());

    Storage::disk('local')->assertExists($batch->fresh()->file_path);
    expect($batch->fresh()->file_path)->toStartWith('disbursements/');
});

it('puts the net pay and the account number in the bank file', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::BankUpload, User::factory()->create());
    $contents = Storage::disk('local')->get($batch->fresh()->file_path);

    expect($contents)->toContain($employee->employee_number)
        // The account number is decrypted only here, at the point it is needed.
        ->toContain('001234567890')
        // Net pay, which is what the bank is asked to move.
        ->toContain('2102.0000');

    // Stated separately rather than chained: `->not` after `->toContain()` is
    // invisible to static analysis, which is the same reason P0-05 turned the
    // custom money expectation into a plain function.
    expect($contents)->not->toContain('2400.0000');
});

it('refuses a bank upload for somebody with no account on file', function () {
    // Reported rather than skipped. A bank file quietly one line short is
    // somebody not paid, discovered when they say so.
    $employee = hiredEmployee(['employee_number' => 'EMP-NOBANK', 'bank_account_number' => null]);
    $contract = hiring()->issueContract($employee, Carbon::parse('2026-04-01'), null, PayBasis::Daily, '1200.0000');
    hiring()->signContract($contract, Carbon::parse('2026-03-30'), 'M. Reyes');

    $project = Project::factory()->create();
    workedDays($employee->fresh(), $project, ['2026-05-04']);
    $run = approvedRun($employee->fresh(), '2026-05-01', '2026-05-15');

    expect(fn () => disbursement()->prepare($run, DisbursementMethod::BankUpload, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('releases a bank payment on the file, with no signature needed', function () {
    // The bank file IS the evidence for this half. Requiring a signature as well
    // would make every bank payroll wait on paperwork that does not exist.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::BankUpload, User::factory()->create());
    $released = disbursement()->markTransmitted($batch, 'BDO-BATCH-99120', User::factory()->create());

    expect($released->transmitted_at)->not->toBeNull()
        ->and($released->bank_reference)->toBe('BDO-BATCH-99120')
        ->and($released->items()->sole()->released_at)->not->toBeNull()
        ->and($run->fresh()->status)->toBe(PayrollRunStatus::Released);
});

it('refuses to transmit a bank batch with no bank reference', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::BankUpload, User::factory()->create());

    expect(fn () => disbursement()->markTransmitted($batch, '   ', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('does NOT release a cash payout until it is acknowledged', function () {
    // THE ONE THAT MATTERS. Cash without an acknowledgment is indistinguishable
    // from cash that never left the drawer, which is exactly why slide 7 says
    // "with acknowledgment" for this half and not for the other.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::CashPayout, User::factory()->create());

    expect($batch->items()->sole()->released_at)->toBeNull()
        ->and($run->fresh()->status)->toBe(PayrollRunStatus::Approved);
});

it('releases a cash payment when the employee acknowledges it', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::CashPayout, User::factory()->create());
    $item = $batch->items()->sole();

    disbursement()->acknowledge($item, 'M. Reyes', Carbon::parse('2026-06-03'), User::factory()->create());

    expect($item->fresh()->released_at)->not->toBeNull()
        ->and($item->fresh()->acknowledged_by)->toBe('M. Reyes')
        ->and($run->fresh()->status)->toBe(PayrollRunStatus::Released);
});

it('refuses an acknowledgment with nobody named', function () {
    // "Acknowledged" with no name is a tick, and a tick is not evidence anybody
    // received money.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::CashPayout, User::factory()->create());

    expect(fn () => disbursement()->acknowledge($batch->items()->sole(), '  ', Carbon::parse('2026-06-03'), User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('refuses to acknowledge one payment twice', function () {
    // A second acknowledgment on one payment is a second release of the same
    // money, with a signature beside each.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $batch = disbursement()->prepare($run, DisbursementMethod::CashPayout, User::factory()->create());
    $item = $batch->items()->sole();

    disbursement()->acknowledge($item, 'M. Reyes', Carbon::parse('2026-06-03'), User::factory()->create());

    expect(fn () => disbursement()->acknowledge($item->fresh(), 'M. Reyes', Carbon::parse('2026-06-04'), User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('holds the register open while any cash payment is unacknowledged', function () {
    // Two people, one signs. The register is not released — it is partly paid,
    // and a status saying otherwise would close a cutoff that still owes money.
    $organization = Organization::factory()->create();
    $project = Project::factory()->create();

    $first = contractedEmployeeIn($organization, 'EMP-CASH-1');
    $second = contractedEmployeeIn($organization, 'EMP-CASH-2');

    workedDays($first, $project, ['2026-05-04']);
    workedDays($second, $project, ['2026-05-04']);

    $run = payroll()->approve(
        payroll()->compute(payroll()->open($organization, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-15'))),
        User::factory()->create(),
    );

    $batch = disbursement()->prepare($run, DisbursementMethod::CashPayout, User::factory()->create());

    disbursement()->acknowledge($batch->items()->first(), 'One Signer', Carbon::parse('2026-06-03'), User::factory()->create());

    expect($run->fresh()->status)->toBe(PayrollRunStatus::Approved)
        ->and(disbursement()->outstandingFor($batch->fresh()))->toBe(1);
});

it('refuses a second disbursement batch for one register', function () {
    // Two batches against one register is the payroll paid twice, and both
    // batches would reconcile against the same approved figures.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    disbursement()->prepare($run, DisbursementMethod::BankUpload, User::factory()->create());

    expect(fn () => disbursement()->prepare($run->fresh(), DisbursementMethod::CashPayout, User::factory()->create()))
        ->toThrow(QueryException::class);
});

it('renders a payslip PDF to private storage', function () {
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04', '2026-05-05']);
    $run = approvedRun($employee, '2026-05-01', '2026-05-15');

    $payslip = payslips()->render($run->lines()->sole());

    Storage::disk('local')->assertExists($payslip->file_path);
    expect($payslip->file_path)->toStartWith('payslips/')
        ->and(Storage::disk('local')->get($payslip->file_path))->toStartWith('%PDF');
});

it('shows a carried day on the payslip as carried', function () {
    // The held-day mechanic reaching the employee. Without this the payslip
    // shows an extra day from a month they were not paid for, and the first
    // person to notice is the one holding the payslip.
    $employee = contractedEmployee();
    $project = Project::factory()->create();
    $organization = $employee->organization()->sole();

    workedDays($employee, $project, ['2026-05-04'], validate: true);
    workedDays($employee, $project, ['2026-05-05'], validate: false);

    payroll()->approve(computedRun($employee, '2026-05-01', '2026-05-15'), User::factory()->create());

    $held = DailyTimeRecord::query()
        ->where('employee_id', $employee->getKey())
        ->whereDate('work_date', '2026-05-05')
        ->sole();

    timekeeping()->validate($held, User::factory()->create());

    $second = payroll()->approve(computedRun($employee, '2026-05-16', '2026-05-31'), User::factory()->create());

    expect(payslips()->summaryFor($second->lines()->sole()))
        ->toHaveKey('carried_days', 1);
});

it('refuses a payslip for a register that has not been approved', function () {
    // A payslip is a statement of what somebody is being paid. Issued off a
    // computed register it states a figure the variance review might still change.
    $employee = contractedEmployee();
    $project = Project::factory()->create();

    workedDays($employee, $project, ['2026-05-04']);
    $run = computedRun($employee, '2026-05-01', '2026-05-15');

    expect(fn () => payslips()->render($run->lines()->sole()))
        ->toThrow(DomainException::class);
});
