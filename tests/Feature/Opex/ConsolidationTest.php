<?php

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\LedgerPoster;
use App\Domain\Procurement\PayablesService;
use App\Domain\Procurement\ThreeWayMatchService;
use App\Models\CutoffCalendar;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Consolidation, the project P&L, and the cash requirement — P4-08 and P4-09
|--------------------------------------------------------------------------
|
| Slide 8's outputs: "OPEX per project against budget; cost per unit of
| accomplishment; variance report by cost centre; **cash requirement for the next
| month**; input to project P&L; forecast cost to completion."
|
| F16 is the cash requirement, and PHASE-PLAN.md is specific about why it is a
| finding: "PLAN.md §4's ledger section produces a P&L, cost per unit and
| forecast to completion, but no cash requirement." A P&L is not a cash figure —
| it recognises revenue when it is earned and cost when it is incurred, and the
| month's cash need is about neither.
|
| **The P&L is assembled by SUMMING, not by classifying.** Every posting was
| categorised when it was written (P0-13's five categories), which is exactly so
| this report can add up rather than decide what things are. A consolidation that
| re-classified would be a second opinion about numbers already reported.
|
| **And it reads the ledger, not the source documents.** The ledger is the
| organising principle; a report built from expenses and payroll lines directly
| would be a second path to the same figures, and the two would eventually
| disagree.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-06-02 09:00:00');

    foreach ([CutoffType::Billing, CutoffType::Opex, CutoffType::Payroll] as $type) {
        CutoffCalendar::query()->create([
            'project_id' => null,
            'cutoff_type' => $type,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'cutoff_at' => '2026-12-31 17:00:00',
        ]);
    }
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Post one row of each category straight through the ledger poster.
 *
 * The consolidation's job is to read the ledger, so the fastest honest way to
 * set one up is to write the ledger — through `LedgerPoster`, which is the only
 * code permitted to, so the cutoff and organisation checks still run.
 */
function ledgerProject(): Project
{
    [$project, $costCode] = budgetedProject('100000.0000');

    $rows = [
        [LedgerCategory::Revenue, '500000.0000'],
        [LedgerCategory::Material, '120000.0000'],
        [LedgerCategory::Subcontract, '80000.0000'],
        [LedgerCategory::Labor, '60000.0000'],
        [LedgerCategory::Overhead, '40000.0000'],
    ];

    foreach ($rows as $index => [$category, $amount]) {
        app(LedgerPoster::class)->post(
            project: $project,
            costCode: $costCode,
            category: $category,
            amount: $amount,
            sourceDocument: $project,
            documentNumber: sprintf('SEED-%s-%d', $category->value, $index),
            cutoffType: CutoffType::Opex,
            documentDate: Carbon::parse('2026-05-15'),
        );
    }

    return $project;
}

it('assembles a project P and L by summing the ledger', function () {
    // Revenue 500,000 less material 120,000, subcontract 80,000, labour 60,000
    // and overhead 40,000 is 200,000 of gross profit.
    $project = ledgerProject();

    $pnl = consolidation()->profitAndLoss($project, 2026, 5);

    expect($pnl['revenue'])->toBe('500000.0000')
        ->and($pnl['material'])->toBe('120000.0000')
        ->and($pnl['subcontract'])->toBe('80000.0000')
        ->and($pnl['labor'])->toBe('60000.0000')
        ->and($pnl['overhead'])->toBe('40000.0000')
        ->and($pnl['total_cost'])->toBe('300000.0000')
        ->and($pnl['gross_profit'])->toBe('200000.0000');
});

it('reports a margin as a percentage of revenue', function () {
    // 200,000 on 500,000 is 40%. The figure the deck's own cost-per-unit
    // conversation starts from.
    $project = ledgerProject();

    expect(consolidation()->profitAndLoss($project, 2026, 5)['margin_percent'])->toBe('40.00');
});

it('reports a zero margin rather than dividing by nothing on a project with no revenue', function () {
    // Every project looks like this in its first months: cost accruing, nothing
    // billed yet. A division by zero here would break the report for exactly the
    // projects most worth watching.
    [$project, $costCode] = budgetedProject('100000.0000');

    app(LedgerPoster::class)->post(
        project: $project,
        costCode: $costCode,
        category: LedgerCategory::Material,
        amount: '120000.0000',
        sourceDocument: $project,
        documentNumber: 'SEED-NOREV',
        cutoffType: CutoffType::Opex,
        documentDate: Carbon::parse('2026-05-15'),
    );

    $pnl = consolidation()->profitAndLoss($project, 2026, 5);

    expect($pnl['revenue'])->toBe('0.0000')
        ->and($pnl['gross_profit'])->toBe('-120000.0000')
        ->and($pnl['margin_percent'])->toBe('0.00');
});

it('excludes a reversed posting and its reversal from the P and L', function () {
    // P0-13 corrects by reversing entries rather than editing, so a corrected
    // cost is TWO rows that net to zero. A report that summed them both would be
    // right by arithmetic and wrong by accident if either were dropped — this
    // asserts the pair nets out.
    $project = ledgerProject();

    $entry = ProjectCostLedgerEntry::query()
        ->where('project_id', $project->getKey())
        ->where('category', LedgerCategory::Material)
        ->sole();

    app(LedgerPoster::class)->reverse($entry, 'Coded to the wrong project.');

    expect(consolidation()->profitAndLoss($project->fresh(), 2026, 5)['material'])->toBe('0.0000');
});

it('computes the cash requirement for the next month', function () {
    // F16. PHASE-PLAN.md: PLAN.md produces a P&L, cost per unit and a forecast,
    // but no cash requirement — and a P&L is not a cash figure.
    $project = ledgerProject();

    $cash = consolidation()->cashRequirement($project, 2026, 5);

    expect($cash)->toHaveKeys(['committed', 'payables', 'payroll', 'retention_held', 'expected_collections', 'net_requirement']);
});

it('counts an approved purchase order as committed cash even before delivery', function () {
    // THE POINT of F16, and what separates it from the P&L. An approved PO is
    // money the company has promised and has not spent; it appears in no ledger
    // category until the goods arrive, and it is exactly what the treasurer needs
    // to know about next month.
    //
    // The order is approved and UNDELIVERED — `acceptedGoods()` would receive it
    // in full, which is the state where nothing is committed any more.
    [$po, $project] = orderAwaitingCountersignature();

    $cash = consolidation()->cashRequirement($project, 2026, 5);

    expect(bccomp($cash['committed'], '0.0000', 4))->toBeGreaterThan(0);
});

it('stops counting an order as committed once the goods have arrived', function () {
    // The other half. Delivered goods are a cost the ledger has taken and a
    // payable the voucher will carry — counting the order as well would have the
    // treasurer setting aside the money twice.
    [$po, $report] = acceptedGoods('500.0000');

    $cash = consolidation()->cashRequirement($po->project()->sole(), 2026, 5);

    expect($cash['committed'])->toBe('0.0000');
});

it('counts an unpaid AP voucher as a payable', function () {
    // Raised, not yet paid: the P&L recognised the cost when the goods arrived,
    // and the cash leaves when somebody signs the cheque.
    [$po, $report] = acceptedGoods('500.0000');
    $project = $po->project()->sole();

    $match = app(ThreeWayMatchService::class)->match($po, $report, 'INV-CASH-1', '124500.0000');
    app(PayablesService::class)->raise($match, 'goods');

    $cash = consolidation()->cashRequirement($project->fresh(), 2026, 5);

    expect(bccomp($cash['payables'], '0.0000', 4))->toBeGreaterThan(0);
});

it('counts an outstanding receivable as an expected collection', function () {
    // Cash coming IN, which is the other half of a requirement. A figure that
    // only counted outflows would tell a treasurer to borrow money the client is
    // about to send.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    $cash = consolidation()->cashRequirement($billing->project()->sole(), 2026, 5);

    expect($cash['expected_collections'])->toBe((string) $invoice->collectible_amount);
});

it('excludes retention from expected collections', function () {
    // Retention is held by agreement until the defects liability period ends. A
    // cash forecast that counted it would have the treasurer expecting money
    // nobody intends to send for a year.
    $billing = approvedBilling();
    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-21'), 'services');

    // Withholding is its own step (P2-07), not a side effect of invoicing — so
    // the retention ledger is empty until somebody records it.
    retention()->withhold($invoice);

    $cash = consolidation()->cashRequirement($billing->project()->sole(), 2026, 5);

    expect(bccomp($cash['expected_collections'], (string) $invoice->gross_amount, 4))->toBeLessThan(0)
        ->and($cash['retention_held'])->toBe((string) $invoice->retention_amount);
});

it('nets the requirement as outflows less expected collections', function () {
    // The single number the deck asks for: what the month needs beyond what it
    // expects to receive.
    $project = ledgerProject();

    $cash = consolidation()->cashRequirement($project, 2026, 5);

    $outflows = bcadd(bcadd($cash['committed'], $cash['payables'], 4), $cash['payroll'], 4);

    expect($cash['net_requirement'])->toBe(bcsub($outflows, $cash['expected_collections'], 4));
});

it('consolidates every project in an organization', function () {
    // Slide 8's "OPEX per project" and the consolidation are different reports:
    // one is per project, the other is the company. Both come from the same sums.
    $project = ledgerProject();

    $rows = consolidation()->forOrganization($project->organization()->sole(), 2026, 5);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['project_code'])->toBe($project->code)
        ->and($rows[0]['gross_profit'])->toBe('200000.0000');
});
