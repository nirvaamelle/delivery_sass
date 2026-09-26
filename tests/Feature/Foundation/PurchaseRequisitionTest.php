<?php

use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Gates\GateFailedException;
use App\Domain\Requisitions\BudgetExceededException;
use App\Domain\Requisitions\RequisitionService;
use App\Domain\Requisitions\RequisitionStatus;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\CostCode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PurchaseRequisition;

/*
|--------------------------------------------------------------------------
| Purchase requisitions — P0-17
|--------------------------------------------------------------------------
|
| PLAN.md §5's very first control:
|
|   "No PR without a cost code and confirmed budget availability."
|
| The cost code half was settled in P0-05 by a non-nullable foreign key. This
| task is the other half, and it is the harder one: availability is not a column
| you can constrain, it is a calculation over everything already committed
| against that cost code.
|
| The PR is deliberately minimal. The full procurement chain — RFQ, canvass,
| tabulation, PO — is Phase 1. What exists here is the one document the Phase 0
| exit gate is worded around, built so the substrate underneath it (numbering,
| gates, approvals, budgets) is exercised by something real rather than by a
| test double.
|
*/

function requisitions(): RequisitionService
{
    return app(RequisitionService::class);
}

beforeEach(function () {
    // Submission routes through the authority matrix, so the matrix has to
    // exist. These mirror ApprovalMatrixSeeder — and like it, the amounts are
    // PLACEHOLDER: Part D item 1, since the deck shows bands and not numbers.
    $router = app(ApprovalRouter::class);
    $router->defineTier('purchase_requisition', 1, '0.0000', '50000.0000', ['project-manager'], []);
    $router->defineTier('purchase_requisition', 2, '50000.0001', '500000.0000', ['project-manager', 'procurement-head'], []);
    $router->defineTier('purchase_requisition', 3, '500000.0001', null, ['procurement-head', 'finance-manager'], []);
});

/**
 * A project that is genuinely ready to spend: signed contract, open budget, and
 * a budgeted cost code.
 *
 * @return array{0: Project, 1: CostCode, 2: Budget}
 */
function readyProject(string $budgeted = '100000.0000'): array
{
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $costCode = CostCode::factory()->for($organization)->create(['code' => '02.10.100']);

    Contract::factory()->for($project)->create(['status' => ContractStatus::Signed]);
    $budget = Budget::factory()->for($project)->create(['status' => BudgetStatus::Open]);

    $budget->lines()->create([
        'cost_code_id' => $costCode->getKey(),
        'amount' => $budgeted,
    ]);

    return [$project, $costCode, $budget];
}

it('raises a requisition with a gapless document number', function () {
    [$project, $costCode] = readyProject();

    $pr = requisitions()->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Portland cement', 'amount' => '25000.0000'],
    ]);

    expect($pr->number)->toStartWith('PR-')
        ->and($pr->status)->toBe(RequisitionStatus::Draft)
        ->and($pr->total_amount)->toBe('25000.0000');
});

it('refuses a requisition against a project with no signed contract', function () {
    // F14, reached through the document rather than called directly. The gate
    // was registered in P0-11; this proves the requisition actually consults it.
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $costCode = CostCode::factory()->for($organization)->create();
    Budget::factory()->for($project)->create(['status' => BudgetStatus::Open]);

    expect(fn () => requisitions()->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '1000.0000'],
    ]))->toThrow(GateFailedException::class);
});

it('totals its lines exactly', function () {
    [$project, $costCode] = readyProject();

    $pr = requisitions()->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '10000.2500'],
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Rebar', 'amount' => '15000.7500'],
    ]);

    expectMoney($pr->total_amount);
    expect($pr->total_amount)->toBe('25001.0000');
});

it('reports what is still available against a cost code', function () {
    [$project, $costCode] = readyProject('100000.0000');

    expect(requisitions()->availableFor($project, $costCode))->toBe('100000.0000');
});

it('reduces availability by what is already committed', function () {
    // Availability is not the budget line. It is the budget line minus what is
    // already spoken for — otherwise every requisition sees the full budget and
    // the tenth one is approved as readily as the first.
    [$project, $costCode] = readyProject('100000.0000');

    $pr = requisitions()->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '30000.0000'],
    ]);
    requisitions()->submit($pr);

    expect(requisitions()->availableFor($project, $costCode))->toBe('70000.0000');
});

it('rejects a submission that exceeds budget availability', function () {
    // The control itself, and the one the Phase 0 exit gate turns on.
    [$project, $costCode] = readyProject('100000.0000');

    $pr = requisitions()->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '150000.0000'],
    ]);

    expect(fn () => requisitions()->submit($pr))->toThrow(BudgetExceededException::class);
    expect($pr->fresh()->status)->toBe(RequisitionStatus::Draft);
});

it('rejects a submission that exceeds availability only in aggregate', function () {
    // Each line fits; together they do not. Checking line by line would pass
    // this, which is why the check sums per cost code before comparing.
    [$project, $costCode] = readyProject('100000.0000');

    $pr = requisitions()->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '60000.0000'],
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Rebar', 'amount' => '60000.0000'],
    ]);

    expect(fn () => requisitions()->submit($pr))->toThrow(BudgetExceededException::class);
});

it('rejects a submission against a cost code with no budget line at all', function () {
    // An unbudgeted cost code has availability of zero, not unlimited. Treating
    // "no budget line" as "no limit" is how overspend enters unnoticed.
    [$project] = readyProject();
    $unbudgeted = CostCode::factory()
        ->for($project->organization)
        ->create(['code' => '09.99.999']);

    $pr = requisitions()->raise($project, [
        ['cost_code_id' => $unbudgeted->getKey(), 'description' => 'Unplanned', 'amount' => '1.0000'],
    ]);

    expect(fn () => requisitions()->submit($pr))->toThrow(BudgetExceededException::class);
});

it('accepts a submission that fits exactly', function () {
    // The boundary. Spending the last peso of a budget is allowed; spending one
    // more is not.
    [$project, $costCode] = readyProject('100000.0000');

    $pr = requisitions()->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '100000.0000'],
    ]);

    requisitions()->submit($pr);

    expect($pr->fresh()->status)->toBe(RequisitionStatus::Submitted);
});

it('does not count a returned requisition against availability', function () {
    // A returned document is not a commitment. If it still consumed budget, a
    // rejected requisition would block the corrected one that replaces it.
    [$project, $costCode] = readyProject('100000.0000');

    $pr = requisitions()->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '80000.0000'],
    ]);
    requisitions()->submit($pr);
    $pr->update(['status' => RequisitionStatus::Returned]);

    expect(requisitions()->availableFor($project, $costCode))->toBe('100000.0000');
});

it('opens approval steps when a requisition is submitted', function () {
    [$project, $costCode] = readyProject('100000.0000');

    $pr = requisitions()->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '25000.0000'],
    ]);

    requisitions()->submit($pr);

    expect(PurchaseRequisition::query()->count())->toBe(1)
        ->and($pr->approvals()->count())->toBeGreaterThan(0);
});
