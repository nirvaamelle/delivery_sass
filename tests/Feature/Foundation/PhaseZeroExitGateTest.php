<?php

use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Requisitions\BudgetExceededException;
use App\Domain\Requisitions\RequisitionService;
use App\Domain\Requisitions\RequisitionStatus;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\CostCode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 0 exit gate — P0-18
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Part C states the gate as a behaviour, not a checklist:
|
|   "A PR can be raised, routed through a two-tier approval, and rejected by
|    the budget check — end to end, on staging, deployed by push."
|
| This file demonstrates every clause except the last. The staging clause needs
| a server, which is Part D item 12 and unanswered, so P0-16 stays `blocked` and
| the gate is not claimed complete on the strength of this file alone. What this
| does prove is that the substrate works together: gates, numbering, budgets,
| the authority matrix and approvals, exercised by one real document.
|
| The rejection is the point. A gate that only demonstrates the happy path
| demonstrates nothing — the entire reason this system exists, per the deck, is
| the three failure modes it refuses.
|
*/

/**
 * @return array{project: Project, costCode: CostCode, pm: User, head: User}
 */
function gateFixture(string $budgeted = '400000.0000'): array
{
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create(['code' => 'MBI-2026-001']);
    $costCode = CostCode::factory()->for($organization)->create(['code' => '02.10.100']);

    // A project genuinely able to spend — F14's two conditions.
    Contract::factory()->for($project)->create(['status' => ContractStatus::Signed]);
    $budget = Budget::factory()->for($project)->create(['status' => BudgetStatus::Open]);
    $budget->lines()->create(['cost_code_id' => $costCode->getKey(), 'amount' => $budgeted]);

    // The authority matrix. PLACEHOLDER: Part D item 1 — bands, not agreed
    // amounts. Tier 2 is the two-signature band the gate calls for.
    $router = app(ApprovalRouter::class);
    $router->defineTier('purchase_requisition', 1, '0.0000', '50000.0000', ['project-manager'], []);
    $router->defineTier('purchase_requisition', 2, '50000.0001', '500000.0000', ['project-manager', 'procurement-head'], []);

    foreach (['project-manager', 'procurement-head'] as $role) {
        Role::findOrCreate($role);
    }

    $pm = User::factory()->create(['name' => 'Project Manager']);
    $pm->assignRole('project-manager');

    $head = User::factory()->create(['name' => 'Procurement Head']);
    $head->assignRole('procurement-head');

    return ['project' => $project, 'costCode' => $costCode, 'pm' => $pm, 'head' => $head];
}

it('raises a requisition and routes it through a two-tier approval', function () {
    $f = gateFixture();
    $service = app(RequisitionService::class);

    // 1. Raised against a project that has cleared F14.
    $pr = $service->raise($f['project'], [
        ['cost_code_id' => $f['costCode']->getKey(), 'description' => 'Portland cement, 500 bags', 'amount' => '250000.0000'],
    ]);

    expect($pr->number)->toStartWith('PR-2026-')
        ->and($pr->status)->toBe(RequisitionStatus::Draft);

    // 2. Submitted — the amount lands in the two-signature band, and the
    //    requisition does not get to choose how many signatures it needs.
    $service->submit($pr);

    $steps = $pr->approvals()->orderBy('step')->get();

    expect($steps)->toHaveCount(2)
        ->and($steps->pluck('approver_role')->all())->toBe(['project-manager', 'procurement-head'])
        ->and($steps->pluck('tier')->unique()->all())->toBe([2]);

    // 3. Both signatures, in order.
    $service->approve($pr, $steps[0], $f['pm'], 'Quantities match the BOM.');
    $service->approve($pr, $steps[1], $f['head'], 'Within the approved budget.');

    expect($pr->fresh()->status)->toBe(RequisitionStatus::Approved);
});

it('does not approve the requisition on one signature of two', function () {
    // The half-approved state is the one worth testing. A tier that requires
    // two signatures and acts on one is not a two-tier approval.
    $f = gateFixture();
    $service = app(RequisitionService::class);

    $pr = $service->raise($f['project'], [
        ['cost_code_id' => $f['costCode']->getKey(), 'description' => 'Cement', 'amount' => '250000.0000'],
    ]);
    $service->submit($pr);

    $steps = $pr->approvals()->orderBy('step')->get();
    $service->approve($pr, $steps[0], $f['pm']);

    expect($pr->fresh()->status)->toBe(RequisitionStatus::Submitted);
});

it('rejects a requisition by the budget check', function () {
    // THE GATE. PLAN.md §5's first control, refusing a real document: the
    // project is fully able to spend — signed contract, open budget, valid cost
    // code — and the requisition is still refused, because the money is not
    // there.
    $f = gateFixture('400000.0000');
    $service = app(RequisitionService::class);

    $pr = $service->raise($f['project'], [
        ['cost_code_id' => $f['costCode']->getKey(), 'description' => 'Cement', 'amount' => '650000.0000'],
    ]);

    expect(fn () => $service->submit($pr))
        ->toThrow(BudgetExceededException::class, '400000.0000');

    // Refused means refused: no status change, and nothing routed to anybody.
    expect($pr->fresh()->status)->toBe(RequisitionStatus::Draft)
        ->and($pr->approvals()->count())->toBe(0);
});

it('rejects the second requisition once the first has consumed the budget', function () {
    // The subtler half of the same control. The first requisition is entirely
    // valid; the second is identical and must fail, because availability moved
    // when the first was submitted.
    $f = gateFixture('400000.0000');
    $service = app(RequisitionService::class);

    $first = $service->raise($f['project'], [
        ['cost_code_id' => $f['costCode']->getKey(), 'description' => 'Cement', 'amount' => '250000.0000'],
    ]);
    $service->submit($first);

    $second = $service->raise($f['project'], [
        ['cost_code_id' => $f['costCode']->getKey(), 'description' => 'More cement', 'amount' => '250000.0000'],
    ]);

    expect(fn () => $service->submit($second))->toThrow(BudgetExceededException::class);
    expect($service->availableFor($f['project'], $f['costCode']))->toBe('150000.0000');
});

it('returns the requisition, with the reason, when an approver sends it back', function () {
    // The deck's approved-or-returned branch, end to end.
    $f = gateFixture();
    $service = app(RequisitionService::class);

    $pr = $service->raise($f['project'], [
        ['cost_code_id' => $f['costCode']->getKey(), 'description' => 'Cement', 'amount' => '250000.0000'],
    ]);
    $service->submit($pr);

    $step = $pr->approvals()->orderBy('step')->first();
    $service->returnForRevision($pr, $step, $f['pm'], 'Quantities do not match the BOM.');

    expect($pr->fresh()->status)->toBe(RequisitionStatus::Returned)
        ->and($step->fresh()->decision)->toBe(ApprovalDecision::Returned)
        ->and($step->fresh()->remarks)->toBe('Quantities do not match the BOM.');
});

it('frees the budget again once a requisition is returned', function () {
    // Closing the loop: the returned document releases what it had claimed, so
    // the corrected resubmission is not blocked by its own predecessor.
    $f = gateFixture('400000.0000');
    $service = app(RequisitionService::class);

    $pr = $service->raise($f['project'], [
        ['cost_code_id' => $f['costCode']->getKey(), 'description' => 'Cement', 'amount' => '250000.0000'],
    ]);
    $service->submit($pr);

    $step = $pr->approvals()->orderBy('step')->first();
    $service->returnForRevision($pr, $step, $f['pm'], 'Wrong unit price.');

    expect($service->availableFor($f['project'], $f['costCode']))->toBe('400000.0000');
});
