<?php

use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Approvals\ApprovalDecisions;
use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Approvals\ApproverLacksAuthorityException;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Requisitions\RequisitionService;
use App\Domain\Requisitions\RequisitionStatus;
use App\Filament\Resources\Approvals\Pages\ListApprovals;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\CostCode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PurchaseOrder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| A decision in the inbox moves the document
|--------------------------------------------------------------------------
|
| Found 2026-09-14 while wiring the finance screens. The inbox called
| ApprovalRouter directly, which records the signature on the approval step and
| nothing else. The status change — a purchase order becoming APPROVED once its
| last signature lands, a requisition becoming RETURNED and freeing its budget —
| lives in each document's own service, and the inbox never called it.
|
| So every document approved from the screen stayed "submitted" for ever: the
| signatures were on file, and the chain behind them (an RFQ for the approved
| requisition, a receiving report for the approved order) refused to start.
| Every existing test approved through the services, which is why none of them
| saw it.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

/**
 * A purchase order submitted for approval, at an amount that picks its tier.
 */
function submittedOrder(string $amount): PurchaseOrder
{
    defineProcurementTiers();
    $canvass = canvassed($amount);

    $order = purchaseOrders()->raise($canvass['tabulation'], [
        ['description' => 'Cement, Type 1', 'quantity' => '1.0000', 'unit_price' => $amount],
    ]);

    return purchaseOrders()->submit($order)->fresh();
}

it('approves a purchase order when its only signature is given in the inbox', function () {
    $order = submittedOrder('40000.0000');
    actingAs(userWithRole('project-manager'));

    Livewire::test(ListApprovals::class)
        ->callAction(TestAction::make('approve')->table($order->approvals()->sole()), data: ['remarks' => 'Within budget.']);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

it('keeps a two-signature purchase order submitted until the second signature', function () {
    $order = submittedOrder('124500.0000');
    $signer = userWithRole('project-manager');
    $signer->assignRole('procurement-head');
    actingAs($signer);

    [$first, $second] = $order->approvals()->orderBy('step')->get()->all();

    Livewire::test(ListApprovals::class)
        ->callAction(TestAction::make('approve')->table($first), data: ['remarks' => '']);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);

    Livewire::test(ListApprovals::class)
        ->callAction(TestAction::make('approve')->table($second), data: ['remarks' => '']);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

it('returns a purchase order to revision from the inbox', function () {
    $order = submittedOrder('40000.0000');
    actingAs(userWithRole('project-manager'));

    Livewire::test(ListApprovals::class)
        ->callAction(TestAction::make('return')->table($order->approvals()->sole()), data: ['reason' => 'Wrong cement grade.']);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Returned)
        ->and($order->approvals()->sole()->decision)->toBe(ApprovalDecision::Returned);
});

it('returns a purchase requisition from the inbox, freeing the budget it had claimed', function () {
    $router = app(ApprovalRouter::class);
    $router->defineTier('purchase_requisition', 1, '0.0000', '50000.0000', ['project-manager'], []);

    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $costCode = CostCode::factory()->for($organization)->create(['code' => '03.10.100']);
    Contract::factory()->for($project)->create(['status' => ContractStatus::Signed]);
    Budget::factory()->for($project)->create(['status' => BudgetStatus::Open])
        ->lines()->create(['cost_code_id' => $costCode->getKey(), 'amount' => '30000.0000']);

    $requisitions = app(RequisitionService::class);
    $pr = $requisitions->submit($requisitions->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Portland cement', 'amount' => '25000.0000'],
    ]));

    expect($requisitions->availableFor($project, $costCode))->toBe('5000.0000');

    actingAs(userWithRole('project-manager'));

    Livewire::test(ListApprovals::class)
        ->callAction(TestAction::make('return')->table($pr->approvals()->sole()), data: ['reason' => 'Quantities do not match the BOM.']);

    expect($pr->fresh()->status)->toBe(RequisitionStatus::Returned)
        ->and($requisitions->availableFor($project, $costCode))->toBe('30000.0000');
});

it('still refuses a signer who does not hold the step\'s role', function () {
    $order = submittedOrder('40000.0000');
    $step = $order->approvals()->sole();

    expect(fn () => app(ApprovalDecisions::class)->approve($step, userWithRole('finance-manager'), null))
        ->toThrow(ApproverLacksAuthorityException::class);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);
});
