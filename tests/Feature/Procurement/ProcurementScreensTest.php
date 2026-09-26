<?php

use App\Domain\Approvals\ApprovalDecisions;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\RfqService;
use App\Domain\Procurement\RfqStatus;
use App\Domain\Procurement\SoleSourceReason;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectRole;
use App\Domain\Requisitions\RequisitionService;
use App\Domain\Requisitions\RequisitionStatus;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseRequisitions\Pages\ListPurchaseRequisitions;
use App\Filament\Resources\Rfqs\Pages\ListRfqs;
use App\Filament\Resources\Subcontracts\Pages\ListSubcontracts;
use App\Models\BidTabulation;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\CostCode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\Subcontract;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Procurement on screen — OS-05
|--------------------------------------------------------------------------
|
| The head of the chain was list-only: nothing could raise a requisition, open a
| canvass, record a quote, produce an abstract of canvass, or award an order.
| Every gate behind those acts was built and tested and none of them could be
| reached by the people the gates exist for.
|
| The refusals are what these tests are about. A requisition against a project
| with no signed contract or no budget; a canvass against an unapproved
| requisition; a quote before the RFQ is issued; an award to anybody but the
| recommended vendor.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

/**
 * A project that is genuinely ready to spend: signed contract, open budget and a
 * budgeted cost code.
 *
 * @return array{0: Project, 1: CostCode}
 */
function spendableProject(string $budgeted = '100000.0000', ?User $assignee = null): array
{
    defineProcurementTiers('purchase_requisition');

    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    // ProjectScope hides every project a user is not on, so a buyer who is not
    // assigned cannot requisition against it — the rule, not a fixture detail.
    if ($assignee !== null) {
        app(ProjectAccessService::class)->assign($assignee, $project, ProjectRole::ProjectManager);
    }
    $costCode = CostCode::factory()->for($organization)->create(['code' => '02.10.'.random_int(100, 999)]);

    Contract::factory()->for($project)->create(['status' => ContractStatus::Signed]);
    Budget::factory()->for($project)->create(['status' => BudgetStatus::Open])
        ->lines()->create(['cost_code_id' => $costCode->getKey(), 'amount' => $budgeted]);

    return [$project, $costCode];
}

/**
 * An approved requisition on a project the buyer is assigned to.
 *
 * `procurement-head` is not one of ProjectScope's unscoped roles, so a buyer who
 * is not on the project cannot canvass for it. Whether a central procurement
 * function should read across every project is a question for the client
 * (DECISIONS-PENDING.md), not something a fixture should decide.
 */
function approvedRequisitionFor(User $buyer): PurchaseRequisition
{
    $requisition = PurchaseRequisition::factory()->create(['status' => RequisitionStatus::Approved]);

    assignBuyerTo($buyer, (int) $requisition->project_id);

    return $requisition;
}

/**
 * A canvass on a project the buyer is assigned to.
 *
 * @return array<string, mixed>
 */
function canvassedFor(User $buyer, string $winningAmount): array
{
    $canvass = canvassed($winningAmount);

    assignBuyerTo($buyer, (int) $canvass['pr']->project_id);

    return $canvass;
}

function assignBuyerTo(User $buyer, int $projectId): void
{
    app(ProjectAccessService::class)->assign(
        $buyer,
        Project::withoutProjectScope(fn (): Project => Project::query()->findOrFail($projectId)),
        ProjectRole::ProjectManager,
    );
}

/*
|--------------------------------------------------------------------------
| Requisition
|--------------------------------------------------------------------------
*/

it('raises a requisition from the screen', function () {
    $buyer = userWithRole('project-manager');
    actingAs($buyer);
    [$project, $costCode] = spendableProject(assignee: $buyer);

    Livewire::test(ListPurchaseRequisitions::class)
        ->callAction(TestAction::make('raiseRequisition'), data: [
            'project_id' => $project->getKey(),
            'lines' => [
                ['cost_code_id' => $costCode->getKey(), 'description' => 'Portland cement', 'amount' => '25000.0000'],
            ],
        ]);

    expect(PurchaseRequisition::query()->sole()->status)->toBe(RequisitionStatus::Draft);
});

it('shows the gate refusal when the project has no signed contract, and raises nothing', function () {
    // F14, reached the way a buyer would reach it.
    $buyer = userWithRole('project-manager');
    actingAs($buyer);
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $costCode = CostCode::factory()->for($organization)->create();
    Budget::factory()->for($project)->create(['status' => BudgetStatus::Open]);

    // Assigned, so what refuses is F14 and not the scope.
    app(ProjectAccessService::class)->assign($buyer, $project, ProjectRole::ProjectManager);

    Livewire::test(ListPurchaseRequisitions::class)
        ->callAction(TestAction::make('raiseRequisition'), data: [
            'project_id' => $project->getKey(),
            'lines' => [
                ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '1000.0000'],
            ],
        ])
        ->assertNotified();

    expect(PurchaseRequisition::query()->count())->toBe(0);
});

it('submits a requisition, committing its budget', function () {
    $buyer = userWithRole('project-manager');
    actingAs($buyer);
    [$project, $costCode] = spendableProject(assignee: $buyer);
    $requisitions = app(RequisitionService::class);

    $pr = $requisitions->raise($project, [
        ['cost_code_id' => $costCode->getKey(), 'description' => 'Cement', 'amount' => '25000.0000'],
    ]);

    Livewire::test(ListPurchaseRequisitions::class)
        ->callAction(TestAction::make('submitRequisition')->table($pr));

    expect($pr->fresh()->status)->toBe(RequisitionStatus::Submitted)
        ->and($requisitions->availableFor($project, $costCode))->toBe('75000.0000');
});

/*
|--------------------------------------------------------------------------
| The canvass
|--------------------------------------------------------------------------
*/

it('opens a canvass against an approved requisition', function () {
    $buyer = userWithRole('procurement-head');
    actingAs($buyer);
    $pr = approvedRequisitionFor($buyer);

    Livewire::test(ListRfqs::class)
        ->callAction(TestAction::make('openRfq'), data: [
            'purchase_requisition_id' => $pr->getKey(),
            'quotation_deadline' => '2026-05-20',
        ]);

    expect(Rfq::query()->sole()->status)->toBe(RfqStatus::Draft);
});

it('invites, issues, records quotes and produces the abstract of canvass', function () {
    $buyer = userWithRole('procurement-head');
    actingAs($buyer);
    $pr = approvedRequisitionFor($buyer);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'));

    // Three, because PLAN.md §5's minimum is three quotes and the screen is
    // held to it: two vendors is a sole source wearing a canvass's clothes.
    $cheapest = accreditedVendorNamed('VEN-LOW-'.uniqid());
    $dearer = accreditedVendorNamed('VEN-MID-'.uniqid());
    $dearest = accreditedVendorNamed('VEN-HIGH-'.uniqid());

    foreach ([$cheapest, $dearer, $dearest] as $vendor) {
        Livewire::test(ListRfqs::class)
            ->callAction(TestAction::make('inviteVendor')->table($rfq->fresh()), data: ['vendor_id' => $vendor->getKey()]);
    }

    Livewire::test(ListRfqs::class)
        ->callAction(TestAction::make('issueRfq')->table($rfq->fresh()));

    foreach ([[$cheapest, '124500.0000'], [$dearer, '131750.0000'], [$dearest, '139000.0000']] as [$vendor, $price]) {
        Livewire::test(ListRfqs::class)
            ->callAction(TestAction::make('recordQuote')->table($rfq->fresh()), data: [
                'vendor_id' => $vendor->getKey(),
                'lines' => [['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => $price]],
            ]);
    }

    expect(Quote::query()->count())->toBe(3);

    Livewire::test(ListRfqs::class)
        ->callAction(TestAction::make('tabulate')->table($rfq->fresh()));

    // Computed from the quotes, and it recommends the lowest — the award is
    // limited to that vendor, so a typed recommendation would justify nothing.
    expect(BidTabulation::query()->sole()->recommended_vendor_id)->toBe($cheapest->getKey());
});

it('refuses a quote before the RFQ is issued, by not offering the act', function () {
    $buyer = userWithRole('procurement-head');
    actingAs($buyer);
    $pr = approvedRequisitionFor($buyer);
    $rfq = app(RfqService::class)->open($pr, Carbon::parse('2026-05-20'));

    Livewire::test(ListRfqs::class)
        ->assertActionHidden(TestAction::make('recordQuote')->table($rfq))
        ->assertActionVisible(TestAction::make('inviteVendor')->table($rfq));
});

it('files a sole-source justification on a sole-source canvass only', function () {
    $buyer = userWithRole('procurement-head');
    actingAs($buyer);
    defineProcurementTiers();

    $pr = approvedRequisitionFor($buyer);
    $rfqService = app(RfqService::class);

    $ordinary = $rfqService->open($pr, Carbon::parse('2026-05-20'));
    Livewire::test(ListRfqs::class)->assertActionHidden(TestAction::make('justifySoleSource')->table($ordinary));

    $pr2 = approvedRequisitionFor($buyer);
    $sole = $rfqService->open($pr2, Carbon::parse('2026-05-20'), soleSource: true);
    $vendor = accreditedVendorNamed('VEN-ONLY-'.uniqid());
    $rfqService->invite($sole, $vendor);

    Livewire::test(ListRfqs::class)
        ->callAction(TestAction::make('justifySoleSource')->table($sole->fresh()), data: [
            'vendor_id' => $vendor->getKey(),
            'reason' => SoleSourceReason::ProprietaryItem->value,
            'narrative' => 'Only authorised distributor of the specified valve.',
            'amount' => '85000.0000',
        ]);

    expect($sole->fresh()->justification)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The order
|--------------------------------------------------------------------------
*/

it('submits a purchase order and records the vendor countersignature', function () {
    $buyer = userWithRole('procurement-head');
    actingAs($buyer);
    defineProcurementTiers();
    $canvass = canvassedFor($buyer, '40000.0000');

    $order = purchaseOrders()->raise($canvass['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '40000.0000'],
    ]);

    Livewire::test(ListPurchaseOrders::class)
        ->callAction(TestAction::make('submitOrder')->table($order));

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Submitted);

    // Approved through the matrix, the way the inbox does it.
    $signer = userWithRole('project-manager');
    app(ApprovalDecisions::class)->approve($order->approvals()->sole(), $signer, null);

    Livewire::test(ListPurchaseOrders::class)
        ->callAction(TestAction::make('countersignOrder')->table($order->fresh()), data: [
            'countersigned_at' => '2026-05-16',
            'signatory' => 'R. Santos',
        ]);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Countersigned);
});

it('offers no countersignature until the company has approved the order', function () {
    // Mobilization opens on a countersigned order, so an order that never met
    // the authority matrix could otherwise open a site.
    $buyer = userWithRole('procurement-head');
    actingAs($buyer);
    defineProcurementTiers();
    $canvass = canvassedFor($buyer, '40000.0000');

    $order = purchaseOrders()->raise($canvass['tabulation'], [
        ['description' => 'Cement', 'quantity' => '1.0000', 'unit_price' => '40000.0000'],
    ]);

    Livewire::test(ListPurchaseOrders::class)
        ->assertActionHidden(TestAction::make('countersignOrder')->table($order));
});

/*
|--------------------------------------------------------------------------
| Subcontracts
|--------------------------------------------------------------------------
*/

it('awards a subcontract from the screen', function () {
    $buyer = userWithRole('procurement-head');
    actingAs($buyer);
    $project = Project::factory()->create();
    app(ProjectAccessService::class)->assign($buyer, $project, ProjectRole::ProjectManager);
    $vendor = bondedSubcon('SUB-'.uniqid());

    Livewire::test(ListSubcontracts::class)
        ->callAction(TestAction::make('awardSubcontract'), data: [
            'project_id' => $project->getKey(),
            'vendor_id' => $vendor->getKey(),
            'scope_of_work' => 'Structural steel erection',
            'contract_amount' => '2500000.0000',
            'works_start' => '2026-06-01',
            'works_end' => '2026-09-30',
        ]);

    expect((string) Subcontract::query()->sole()->contract_amount)->toBe('2500000.0000');
});

it('shows the refusal when the works end before they start', function () {
    $buyer = userWithRole('procurement-head');
    actingAs($buyer);
    $project = Project::factory()->create();
    app(ProjectAccessService::class)->assign($buyer, $project, ProjectRole::ProjectManager);
    $vendor = bondedSubcon('SUB-'.uniqid());

    Livewire::test(ListSubcontracts::class)
        ->callAction(TestAction::make('awardSubcontract'), data: [
            'project_id' => $project->getKey(),
            'vendor_id' => $vendor->getKey(),
            'scope_of_work' => 'Structural steel erection',
            'contract_amount' => '2500000.0000',
            'works_start' => '2026-09-30',
            'works_end' => '2026-06-01',
        ])
        ->assertNotified();

    expect(Subcontract::query()->count())->toBe(0);
});
