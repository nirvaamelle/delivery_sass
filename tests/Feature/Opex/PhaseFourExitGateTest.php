<?php

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Gates\GateFailedException;
use App\Domain\Opex\CashAdvanceStatus;
use App\Domain\Opex\ExpenseStatus;
use App\Domain\Opex\OpexStage;
use App\Domain\Opex\PeriodBarredException;
use App\Domain\Posting\LedgerCategory;
use App\Models\CutoffCalendar;
use App\Models\Expense;
use App\Models\PayrollDeduction;
use App\Models\ProjectCostLedgerEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Phase 4 exit gate — P4-11
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Part C states it as behaviour:
|
|   "One month-end close on staging: an expense rejected for a missing cost code
|    and **permanently barred from the period**, and a **12% variance blocking
|    the close** until explained. Overhead appears in the ledger."
|
| Three clauses, and two of them are refusals — which by now is the shape every
| exit gate in this build has taken. The argument was never that a month can be
| closed; any calendar closes a month. It is that a month with an unexplained
| variance in it cannot be.
|
| The first clause has a subtlety the wording hides. "Permanently barred from the
| period" is not "permanently barred": the corrected expense belongs in the NEXT
| period, and a system that refused it forever would mean never booking a real
| cost because somebody forgot a receipt. Both halves are tested.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-30 09:00:00');

    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Opex,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'cutoff_at' => '2026-12-31 17:00:00',
    ]);

    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Opex,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'cutoff_at' => '2026-12-31 17:00:00',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('refuses an expense with no cost code, at the database', function () {
    // CLAUSE 1, first half — and the strongest form the refusal takes. The cost
    // code is a non-nullable foreign key, so no importer, console command or
    // queued job can create an uncoded expense. PLAN.md §5's whole argument is
    // that a control living only in a service is not a control.
    [$project] = budgetedProject();

    expect(fn () => Expense::query()->create([
        'project_id' => $project->getKey(),
        'cost_code_id' => null,
        'number' => 'EXP-GATE-NOCODE',
        'status' => ExpenseStatus::Captured,
        'category' => LedgerCategory::Overhead,
        'amount' => '4500.0000',
        'incurred_on' => '2026-05-10',
        'period_year' => 2026,
        'period_month' => 5,
        'receipt_reference' => 'OR-GATE-NOCODE',
        'description' => 'Uncoded',
    ]))->toThrow(QueryException::class);
});

it('bars a rejected expense from the period it was rejected from', function () {
    // CLAUSE 1, second half. The site corrects the expense and sends it back
    // against the same month — the month whose numbers have been reported.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Generator hire', 'OR-GATE-1');
    expenses()->returnToSite($expense, 'No cost code on the voucher.', User::factory()->create());

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Returned)
        ->and(fn () => expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-14'), 'Generator hire', 'OR-GATE-1'))
        ->toThrow(PeriodBarredException::class);
});

it('accepts the corrected expense in the next period', function () {
    // The half the wording hides. "Barred from the period" is not "barred
    // forever" — a system that refused it outright would mean never booking a
    // real cost because somebody forgot a receipt in May.
    [$project, $costCode] = budgetedProject();

    $expense = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-05-10'), 'Generator hire', 'OR-GATE-2');
    expenses()->returnToSite($expense, 'No cost code on the voucher.', User::factory()->create());

    $corrected = expenses()->capture($project, $costCode, '4500.0000', Carbon::parse('2026-06-03'), 'Generator hire', 'OR-GATE-2');

    expect($corrected->status)->toBe(ExpenseStatus::Captured)
        ->and($corrected->period_month)->toBe(6);
});

it('BLOCKS the close on a twelve percent variance until it is explained', function () {
    // CLAUSE 2, and the gate's own figure. 112,000 against a 100,000 budget line
    // is 12% over — above slide 8's 10% threshold.
    [$project, $costCode, $period] = periodAtReview('112000.0000');

    expect(fn () => opexCalendar()->advanceTo($period, OpexStage::Reporting, User::factory()->create()))
        ->toThrow(GateFailedException::class);

    expect($period->fresh()->stage)->toBe(OpexStage::BudgetReview);

    budgetActual()->explain($period, $costCode, 'Generator hire extended three weeks by the deck pour.', User::factory()->create());

    expect(opexCalendar()->advanceTo($period->fresh(), OpexStage::Reporting, User::factory()->create())->stage)
        ->toBe(OpexStage::Reporting);
});

it('does not block at exactly ten percent', function () {
    // The boundary the gate's figure implies. Slide 8 says ABOVE 10%, so a close
    // that met the standard is not held up — and 12% is chosen as the gate's
    // example precisely because it is over the line.
    [$project, $costCode, $period] = periodAtReview('110000.0000');

    expect(opexCalendar()->advanceTo($period, OpexStage::Reporting, User::factory()->create())->stage)
        ->toBe(OpexStage::Reporting);
});

it('puts overhead in the ledger', function () {
    // CLAUSE 3. The fourth and last chain to reach PLAN.md §1's organising table,
    // after material, revenue and labour.
    [$project, $costCode] = budgetedProject();

    expenses()->capture($project, $costCode, '44500.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-GATE-3');
    expenses()->capture($project, $costCode, '18200.0000', Carbon::parse('2026-05-12'), 'Maynilad', 'OR-GATE-4');

    $entries = overheadPoster()->postPeriod($project, 2026, 5);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->category)->toBe(LedgerCategory::Overhead)
        ->and($entries[0]->amount)->toBe('62700.0000');

    expect(ProjectCostLedgerEntry::query()
        ->where('category', LedgerCategory::Overhead)
        ->where('cost_code', $costCode->code)
        ->exists())->toBeTrue();
});

it('does not post a returned expense to the ledger', function () {
    // Beyond the wording, and deliberate: the gate could be satisfied by a system
    // that bars an expense from capture and then posts it anyway. Slide 8 says a
    // returned expense is "not booked", and the ledger is where that has to hold.
    [$project, $costCode] = budgetedProject();

    $kept = expenses()->capture($project, $costCode, '44500.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-GATE-5');
    $returned = expenses()->capture($project, $costCode, '61000.0000', Carbon::parse('2026-05-11'), 'Generator hire', 'OR-GATE-6');
    expenses()->returnToSite($returned, 'Wrong site.', User::factory()->create());

    $entries = overheadPoster()->postPeriod($project, 2026, 5);

    expect($entries[0]->amount)->toBe('44500.0000')
        ->and($returned->fresh()->status)->toBe(ExpenseStatus::Returned);
});

it('charges an unliquidated advance to payroll at the cutoff', function () {
    // F17, beyond the gate's three clauses. A month-end close that left advanced
    // money unaccounted for would satisfy the wording and miss the finding.
    [$employee, $project, $costCode] = advanceHolder();

    $advance = advances()->release($employee, $project, '10000.0000', Carbon::parse('2026-05-04'), 'Week 19.');
    advances()->liquidateWithExpense($advance, $costCode, '8400.0000', Carbon::parse('2026-05-06'), 'Fuel', 'OR-GATE-7');

    $period = opexCalendar()->open($project->organization()->sole(), 2026, 5);
    opexCalendar()->advanceTo($period, OpexStage::Cutoff, User::factory()->create());

    expect((string) PayrollDeduction::query()->sole()->amount)->toBe('1600.0000')
        ->and($advance->fresh()->status)->toBe(CashAdvanceStatus::ChargedToPayroll);
});

it('runs one month end to end, through every stage in order', function () {
    // "One month-end close", walked. Capture, the cutoff that sweeps advances,
    // coding, validation, consolidation, the budget review that holds until the
    // variance is explained, and reporting.
    [$project, $costCode] = budgetedProject('100000.0000');
    $organization = $project->organization()->sole();

    expenses()->capture($project, $costCode, '112000.0000', Carbon::parse('2026-05-10'), 'Site utilities', 'OR-GATE-8');

    $period = opexCalendar()->open($organization, 2026, 5);

    foreach ([OpexStage::Cutoff, OpexStage::Coding, OpexStage::Validation, OpexStage::Consolidation, OpexStage::BudgetReview] as $stage) {
        $period = opexCalendar()->advanceTo($period->fresh(), $stage, User::factory()->create());
    }

    overheadPoster()->postPeriod($project, 2026, 5);

    // Held at the review by the 12% variance.
    expect(fn () => opexCalendar()->advanceTo($period->fresh(), OpexStage::Reporting, User::factory()->create()))
        ->toThrow(GateFailedException::class);

    budgetActual()->explain($period->fresh(), $costCode, 'Generator hire extended.', User::factory()->create());

    $closed = opexCalendar()->advanceTo($period->fresh(), OpexStage::Reporting, User::factory()->create());

    expect($closed->stage)->toBe(OpexStage::Reporting)
        ->and(ProjectCostLedgerEntry::query()->where('category', LedgerCategory::Overhead)->exists())->toBeTrue();
});

it('produces a P and L and a cash requirement once the month has closed', function () {
    // The consolidation the close exists to produce — slide 8's outputs, and F16
    // beside them.
    [$project, $costCode] = budgetedProject('100000.0000');

    expenses()->capture($project, $costCode, '44500.0000', Carbon::parse('2026-05-10'), 'Meralco', 'OR-GATE-9');
    overheadPoster()->postPeriod($project, 2026, 5);

    $pnl = consolidation()->profitAndLoss($project, 2026, 5);
    $cash = consolidation()->cashRequirement($project, 2026, 5);

    expect($pnl['overhead'])->toBe('44500.0000')
        ->and($pnl['gross_profit'])->toBe('-44500.0000')
        ->and($cash)->toHaveKey('net_requirement');
});
