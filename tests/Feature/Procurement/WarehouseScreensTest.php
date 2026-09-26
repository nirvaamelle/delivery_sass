<?php

use App\Domain\Procurement\InspectionService;
use App\Domain\Procurement\StockService;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectRole;
use App\Filament\Resources\ReceivingReports\Pages\ListReceivingReports;
use App\Filament\Resources\StockCards\Pages\ListStockCards;
use App\Filament\Resources\StockCards\StockCardsResource;
use App\Models\Inspection;
use App\Models\MaterialIssuance;
use App\Models\PhysicalCount;
use App\Models\Project;
use App\Models\ReceivingReport;
use App\Models\ReturnToVendor;
use App\Models\StockCard;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| The warehouse on screen — OS-06
|--------------------------------------------------------------------------
|
| Receiving and stock were list-only, and that blocked more than itself: a
| three-way match needs an INSPECTION, so the payables screens built in OS-01
| could not be reached at all in a clean system. This is the link that was
| missing between "goods arrived" and "we owe for them".
|
| The rules the screens keep:
|
|   - **What was accepted, not what arrived, is what the company owes for.**
|   - **Accepted plus rejected equals received**, line by line. A quantity that
|     vanishes between the two is the discrepancy the control exists for.
|   - **Rejected goods never enter stock**, and a rejection needs a reason.
|   - **Stock cannot go negative**: an issue larger than the balance is either a
|     theft nobody recorded or a count nobody did.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

/**
 * A storekeeper who is actually on the project the goods belong to.
 *
 * `storekeeper` is not one of ProjectScope's unscoped roles, so without the
 * assignment the clerk cannot see the order they are receiving against. Same
 * finding as `procurement-head` — recorded in DECISIONS-PENDING.md rather than
 * worked around.
 */
function storekeeperOn(int $projectId): User
{
    $user = userWithRole('storekeeper');

    app(ProjectAccessService::class)->assign(
        $user,
        Project::withoutProjectScope(fn (): Project => Project::query()->findOrFail($projectId)),
        ProjectRole::Storekeeper,
    );

    return $user;
}

/*
|--------------------------------------------------------------------------
| Receiving
|--------------------------------------------------------------------------
*/

it('receives a delivery against an approved order', function () {
    [$po] = orderAwaitingCountersignature();
    actingAs(storekeeperOn((int) $po->project_id));

    Livewire::test(ListReceivingReports::class)
        ->callAction(TestAction::make('receiveDelivery'), data: [
            'purchase_order_id' => $po->getKey(),
            'delivery_receipt_number' => 'DR-88213',
            'lines' => [
                ['purchase_order_line_id' => $po->lines()->value('id'), 'quantity_received' => '1.0000'],
            ],
        ]);

    expect(ReceivingReport::query()->sole()->delivery_receipt_number)->toBe('DR-88213');
});

it('refuses a delivery larger than what remains outstanding', function () {
    // Goods nobody ordered, on a site that will be invoiced for them.
    [$po] = orderAwaitingCountersignature();
    actingAs(storekeeperOn((int) $po->project_id));

    Livewire::test(ListReceivingReports::class)
        ->callAction(TestAction::make('receiveDelivery'), data: [
            'purchase_order_id' => $po->getKey(),
            'delivery_receipt_number' => 'DR-OVER',
            'lines' => [
                ['purchase_order_line_id' => $po->lines()->value('id'), 'quantity_received' => '99.0000'],
            ],
        ])
        ->assertNotified();

    expect(ReceivingReport::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Inspection — the link the payables chain was missing
|--------------------------------------------------------------------------
*/

it('inspects a delivery, accepting everything', function () {
    [$po, $report] = receivedGoods('500.0000');
    actingAs(storekeeperOn((int) $po->project_id));

    Livewire::test(ListReceivingReports::class)
        ->callAction(TestAction::make('inspectDelivery')->table($report), data: [
            'lines' => [[
                'receiving_report_line_id' => $report->lines()->value('id'),
                'quantity_accepted' => '500.0000',
                'quantity_rejected' => '0',
            ]],
        ]);

    expect(Inspection::query()->sole()->receiving_report_id)->toBe($report->getKey())
        ->and(app(InspectionService::class)->acceptedFor($report->fresh()))->toBe('500.0000');
});

it('refuses an inspection whose quantities do not reconcile', function () {
    // 500 arrived; 400 accepted and 0 rejected loses 100 bags silently.
    [$po, $report] = receivedGoods('500.0000');
    actingAs(storekeeperOn((int) $po->project_id));

    Livewire::test(ListReceivingReports::class)
        ->callAction(TestAction::make('inspectDelivery')->table($report), data: [
            'lines' => [[
                'receiving_report_line_id' => $report->lines()->value('id'),
                'quantity_accepted' => '400.0000',
                'quantity_rejected' => '0',
            ]],
        ])
        ->assertNotified();

    expect(Inspection::query()->count())->toBe(0);
});

it('refuses a rejection with no reason', function () {
    [$po, $report] = receivedGoods('500.0000');
    actingAs(storekeeperOn((int) $po->project_id));

    Livewire::test(ListReceivingReports::class)
        ->callAction(TestAction::make('inspectDelivery')->table($report), data: [
            'lines' => [[
                'receiving_report_line_id' => $report->lines()->value('id'),
                'quantity_accepted' => '400.0000',
                'quantity_rejected' => '100.0000',
                'rejection_reason' => '',
            ]],
        ])
        ->assertNotified();

    expect(Inspection::query()->count())->toBe(0);
});

it('raises the return to vendor for what was rejected', function () {
    [$po, $report] = receivedGoods('500.0000');
    actingAs(storekeeperOn((int) $po->project_id));

    app(InspectionService::class)->inspect($report, [[
        'receiving_report_line_id' => $report->lines()->value('id'),
        'quantity_accepted' => '400.0000',
        'quantity_rejected' => '100.0000',
        'rejection_reason' => 'Torn bags, cement hardened.',
    ]]);

    Livewire::test(ListReceivingReports::class)
        ->callAction(TestAction::make('returnToVendor')->table($report->fresh()), data: ['remarks' => 'Collected by the supplier.']);

    expect(ReturnToVendor::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Stock
|--------------------------------------------------------------------------
*/

it('takes only the accepted quantity into stock', function () {
    // The rule the whole chain rests on: rejected goods never enter stock, so
    // they are never issued to the works and never charged to a project.
    [$po, $report] = receivedGoods('500.0000');
    actingAs(storekeeperOn((int) $po->project_id));

    app(InspectionService::class)->inspect($report, [[
        'receiving_report_line_id' => $report->lines()->value('id'),
        'quantity_accepted' => '400.0000',
        'quantity_rejected' => '100.0000',
        'rejection_reason' => 'Torn bags.',
    ]]);

    Livewire::test(ListReceivingReports::class)
        ->callAction(TestAction::make('receiveIntoStock')->table($report->fresh()));

    expect(app(StockService::class)->onHand(StockCard::query()->sole()))->toBe('400.0000');
});

it('issues material against a cost code', function () {
    [$card, $project, $costCode] = stockedGoods();
    actingAs(storekeeperOn((int) $project->getKey()));

    Livewire::test(ListStockCards::class)
        ->callAction(TestAction::make('issueStock')->table($card), data: [
            'quantity' => '50.0000',
            'cost_code_id' => $costCode->getKey(),
            'issued_to' => 'M. Reyes',
            'purpose' => 'Column pour, grid 4.',
        ]);

    expect(MaterialIssuance::query()->count())->toBe(1)
        ->and(app(StockService::class)->onHand($card->fresh()))->toBe('450.0000');
});

it('refuses an issue larger than what is on hand', function () {
    [$card, $project, $costCode] = stockedGoods();
    actingAs(storekeeperOn((int) $project->getKey()));

    Livewire::test(ListStockCards::class)
        ->callAction(TestAction::make('issueStock')->table($card), data: [
            'quantity' => '9000.0000',
            'cost_code_id' => $costCode->getKey(),
        ])
        ->assertNotified();

    expect(MaterialIssuance::query()->count())->toBe(0);
});

it('records a physical count and computes the variance itself', function () {
    [$card, $project, $costCode] = stockedGoods();
    actingAs(storekeeperOn((int) $project->getKey()));

    Livewire::test(ListStockCards::class)
        ->callAction(TestAction::make('countStock')->table($card), data: [
            'counted_quantity' => '495.0000',
            'remarks' => 'Five bags unaccounted for.',
        ]);

    expect(PhysicalCount::query()->sole()->counted_quantity)->toBe('495.0000');
});

it('keeps the warehouse away from roles with no business in it', function () {
    actingAs(userWithRole('hr-manager'));

    expect(StockCardsResource::canViewAny())->toBeFalse();
});
