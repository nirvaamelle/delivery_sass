<?php

use App\Domain\Gates\GateFailedException;
use App\Domain\Hris\ContractStatus as EmploymentContractStatus;
use App\Domain\Hris\ManpowerStatus;
use App\Domain\Hris\PayBasis;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Manpower requisition and the employment contract — P3-02 and P3-03
|--------------------------------------------------------------------------
|
| Slide 7's first three steps: manpower request → hiring and onboarding → HRIS
| enrollment. And one rule stated flatly in the small print:
|
|   **"Employment contract signed BEFORE first shift."**
|
| That is a gate, not a filing instruction, and it is the one this pair exists
| for. Somebody who works a shift without a signed contract has still worked it —
| the company owes them, and owes the statutory contributions on it, while having
| nothing that says on what terms. Every incentive on a busy site pushes toward
| letting them start and doing the paperwork Friday.
|
| So the gate is declared against the CONTRACT rather than against the timelog,
| for the same reason F2's was declared against the purchase order: the fact
| being tested is a fact about the contract, and the timelog does not exist yet
| at the moment the question is asked.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('raises a manpower requisition against a project', function () {
    $project = Project::factory()->create();

    $requisition = hiring()->requestManpower($project, 'Carpenter', 4, Carbon::parse('2026-06-01'), 'Second shift for the deck pour.');

    expect($requisition->number)->toStartWith('MPR-2026-')
        ->and($requisition->status)->toBe(ManpowerStatus::Requested)
        ->and($requisition->headcount)->toBe(4);
});

it('refuses a requisition for nobody', function () {
    // A request for zero people is a request for nothing, and it would sit in an
    // approval queue consuming somebody's attention.
    $project = Project::factory()->create();

    expect(fn () => hiring()->requestManpower($project, 'Carpenter', 0, Carbon::parse('2026-06-01')))
        ->toThrow(DomainException::class);
});

it('approves a manpower requisition before anyone is onboarded', function () {
    $project = Project::factory()->create();
    $requisition = hiring()->requestManpower($project, 'Carpenter', 4, Carbon::parse('2026-06-01'));

    $approved = hiring()->approveManpower($requisition, User::factory()->create(), 'Approved against the June programme.');

    expect($approved->status)->toBe(ManpowerStatus::Approved)
        ->and($approved->approved_at)->not->toBeNull();
});

it('refuses to approve the same requisition twice', function () {
    // Approving twice would let one request justify two intakes.
    $project = Project::factory()->create();
    $requisition = hiring()->requestManpower($project, 'Carpenter', 4, Carbon::parse('2026-06-01'));
    hiring()->approveManpower($requisition, User::factory()->create());

    expect(fn () => hiring()->approveManpower($requisition->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('issues an employment contract as unsigned', function () {
    // Unsigned is where every contract starts, and the whole rule depends on
    // that being a real state rather than a default nobody looks at.
    $employee = hiredEmployee();

    $contract = hiring()->issueContract($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-12-31'), PayBasis::Daily, '1200.0000');

    expect($contract->status)->toBe(EmploymentContractStatus::Issued)
        ->and($contract->signed_at)->toBeNull();
});

it('refuses a first shift before the contract is signed', function () {
    // THE RULE. Slide 7 states it flatly, and it is a gate rather than a filing
    // instruction: somebody who works an unsigned shift has still worked it, and
    // the company owes them and the statutory contributions on it while holding
    // nothing that says on what terms.
    $employee = hiredEmployee();
    hiring()->issueContract($employee, Carbon::parse('2026-06-01'), null, PayBasis::Daily, '1200.0000');

    expect(fn () => hiring()->assertMayWork($employee->fresh(), Carbon::parse('2026-06-02')))
        ->toThrow(GateFailedException::class);
});

it('refuses a first shift when there is no contract at all', function () {
    // Absence is not permission — the same rule the cutoff calendar and the
    // permit check both follow. A missing contract is the commonest way this
    // fails in practice, not an unsigned one.
    $employee = hiredEmployee();

    expect(fn () => hiring()->assertMayWork($employee, Carbon::parse('2026-06-02')))
        ->toThrow(GateFailedException::class);
});

it('allows a shift once the contract is signed', function () {
    $employee = hiredEmployee();
    $contract = hiring()->issueContract($employee, Carbon::parse('2026-06-01'), null, PayBasis::Daily, '1200.0000');

    hiring()->signContract($contract, Carbon::parse('2026-05-30'), 'M. Reyes', User::factory()->create());

    expect(hiring()->mayWork($employee->fresh(), Carbon::parse('2026-06-02')))->toBeTrue();
});

it('refuses a shift dated before the contract takes effect', function () {
    // A contract signed in May for a June start does not authorise a May shift.
    // The signature is necessary and it is not sufficient.
    $employee = hiredEmployee();
    $contract = hiring()->issueContract($employee, Carbon::parse('2026-06-01'), null, PayBasis::Daily, '1200.0000');
    hiring()->signContract($contract, Carbon::parse('2026-05-30'), 'M. Reyes');

    expect(hiring()->mayWork($employee->fresh(), Carbon::parse('2026-05-28')))->toBeFalse();
});

it('refuses a shift after a fixed-term contract has ended', function () {
    // Project-based hiring runs on fixed terms, and somebody still on site after
    // theirs expired is working uncovered — which is the same failure as an
    // expired permit, one row down.
    $employee = hiredEmployee();
    $contract = hiring()->issueContract($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-08-31'), PayBasis::Daily, '1200.0000');
    hiring()->signContract($contract, Carbon::parse('2026-05-30'), 'M. Reyes');

    expect(hiring()->mayWork($employee->fresh(), Carbon::parse('2026-08-31')))->toBeTrue()
        ->and(hiring()->mayWork($employee->fresh(), Carbon::parse('2026-09-01')))->toBeFalse();
});

it('refuses a signature dated after the contract has already started', function () {
    // "Before first shift" is the rule, so a signature backdated to after the
    // start date is the paperwork being caught up on — which is the exact
    // practice the rule exists to stop, and it must not be recordable as
    // compliant.
    $employee = hiredEmployee();
    $contract = hiring()->issueContract($employee, Carbon::parse('2026-06-01'), null, PayBasis::Daily, '1200.0000');

    expect(fn () => hiring()->signContract($contract, Carbon::parse('2026-06-05'), 'M. Reyes'))
        ->toThrow(DomainException::class);
});

it('refuses a second signature on one contract', function () {
    $employee = hiredEmployee();
    $contract = hiring()->issueContract($employee, Carbon::parse('2026-06-01'), null, PayBasis::Daily, '1200.0000');
    hiring()->signContract($contract, Carbon::parse('2026-05-30'), 'M. Reyes');

    expect(fn () => hiring()->signContract($contract->fresh(), Carbon::parse('2026-05-31'), 'Somebody Else'))
        ->toThrow(DomainException::class);
});

it('refuses a signature with no signatory named', function () {
    // The same argument as the purchase order's countersignature: "somebody
    // signed" cannot settle a dispute.
    $employee = hiredEmployee();
    $contract = hiring()->issueContract($employee, Carbon::parse('2026-06-01'), null, PayBasis::Daily, '1200.0000');

    expect(fn () => hiring()->signContract($contract, Carbon::parse('2026-05-30'), '   '))
        ->toThrow(DomainException::class);
});

it('refuses two live contracts starting on one day for one employee', function () {
    // Two contracts covering the same day means two sets of terms, and payroll
    // would read whichever came back first.
    $employee = hiredEmployee();
    hiring()->issueContract($employee, Carbon::parse('2026-06-01'), null, PayBasis::Daily, '1200.0000');

    expect(fn () => hiring()->issueContract($employee->fresh(), Carbon::parse('2026-06-01'), null, PayBasis::Daily, '1350.0000'))
        ->toThrow(QueryException::class);
});

it('sets the employee rate from the contract when it is signed', function () {
    // The contract is where the rate is agreed, so it is where the rate history
    // starts. Typing it separately afterwards is how the 201 file and the signed
    // terms come to disagree.
    $employee = hiredEmployee();
    $contract = hiring()->issueContract($employee, Carbon::parse('2026-06-01'), null, PayBasis::Daily, '1200.0000');

    hiring()->signContract($contract, Carbon::parse('2026-05-30'), 'M. Reyes');

    expect(employees()->rateOn($employee->fresh(), Carbon::parse('2026-06-15'))?->rate)->toBe('1200.0000');
});
