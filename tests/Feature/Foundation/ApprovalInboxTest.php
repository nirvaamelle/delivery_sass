<?php

use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Approvals\ApprovalRouter;
use App\Filament\Resources\Approvals\ApprovalResource;
use App\Filament\Resources\Approvals\Pages\ListApprovals;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Approvals inbox — P0-15
|--------------------------------------------------------------------------
|
| PLAN.md §3: "Surfaces as one inbox, not seven." Seven inboxes is what you get
| by default — each chain grows its own pending-approval screen, and an approver
| who works across procurement and payroll has to remember to check both. The
| approvals table is polymorphic precisely so this screen can be one list.
|
| The rule the screen enforces is that an inbox shows YOUR work. A pending
| approval addressed to a role you do not hold is not merely un-actionable, it
| should not be on your screen at all — every row an approver has to skip past
| is a row that makes the real one easier to miss.
|
*/

function approver(string ...$roles): User
{
    $user = User::factory()->create();

    foreach ($roles as $role) {
        Role::findOrCreate($role);
        $user->assignRole($role);
    }

    return $user;
}

function tier1(string $documentType = 'purchase_order'): void
{
    app(ApprovalRouter::class)->defineTier(
        $documentType, 1, '0.0000', '50000.0000', ['project-manager'], ['canvass']
    );
}

it('renders the inbox', function () {
    actingAs(approver('project-manager'));

    get(ApprovalResource::getUrl('index'))->assertSuccessful();
});

it('shows a pending approval addressed to a role the user holds', function () {
    actingAs(approver('project-manager'));
    tier1();

    $step = app(ApprovalRouter::class)
        ->request(Budget::factory()->create(), 'purchase_order', '25000.0000')
        ->first();

    Livewire::test(ListApprovals::class)->assertCanSeeTableRecords([$step]);
});

it('hides an approval addressed to a role the user does not hold', function () {
    // The point of the screen. A procurement approval on a payroll approver's
    // inbox is noise, and noise is what makes the real row easy to miss.
    actingAs(approver('finance-manager'));
    tier1();

    $step = app(ApprovalRouter::class)
        ->request(Budget::factory()->create(), 'purchase_order', '25000.0000')
        ->first();

    Livewire::test(ListApprovals::class)->assertCanNotSeeTableRecords([$step]);
});

it('hides an approval that has already been decided', function () {
    $user = approver('project-manager');
    actingAs($user);
    tier1();

    $step = app(ApprovalRouter::class)
        ->request(Budget::factory()->create(), 'purchase_order', '25000.0000')
        ->first();

    app(ApprovalRouter::class)->approve($step, $user, 'Fine.');

    Livewire::test(ListApprovals::class)->assertCanNotSeeTableRecords([$step->fresh()]);
});

it('gathers approvals from different chains into one list', function () {
    // One inbox, not seven: two different source documents, one screen.
    actingAs(approver('project-manager'));
    tier1('purchase_order');
    tier1('ap_voucher');

    $fromProcurement = app(ApprovalRouter::class)
        ->request(Budget::factory()->create(), 'purchase_order', '25000.0000')
        ->first();

    $fromPayables = app(ApprovalRouter::class)
        ->request(Contract::factory()->create(), 'ap_voucher', '10000.0000')
        ->first();

    Livewire::test(ListApprovals::class)
        ->assertCanSeeTableRecords([$fromProcurement, $fromPayables]);
});

it('records an approval made from the inbox', function () {
    $user = approver('project-manager');
    actingAs($user);
    tier1();

    $step = app(ApprovalRouter::class)
        ->request(Budget::factory()->create(), 'purchase_order', '25000.0000')
        ->first();

    Livewire::test(ListApprovals::class)
        ->callAction(
            TestAction::make('approve')->table($step),
            data: ['remarks' => 'Within budget.'],
        );

    $step->refresh();

    expect($step->decision)->toBe(ApprovalDecision::Approved)
        ->and($step->approver_user_id)->toBe($user->id)
        ->and($step->remarks)->toBe('Within budget.')
        ->and($step->decided_at)->not->toBeNull();
});

it('will not return a document without a reason', function () {
    // The deck's approved-or-returned branch depends on the reason: it is what
    // tells the originator what to fix, and in the billing chain it is what
    // stops the same deducted item reappearing next submission.
    actingAs(approver('project-manager'));
    tier1();

    $step = app(ApprovalRouter::class)
        ->request(Budget::factory()->create(), 'purchase_order', '25000.0000')
        ->first();

    Livewire::test(ListApprovals::class)
        ->callAction(TestAction::make('return')->table($step), data: ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($step->fresh()->decision)->toBe(ApprovalDecision::Pending);
});

it('records the reason when a document is returned from the inbox', function () {
    $user = approver('project-manager');
    actingAs($user);
    tier1();

    $step = app(ApprovalRouter::class)
        ->request(Budget::factory()->create(), 'purchase_order', '25000.0000')
        ->first();

    Livewire::test(ListApprovals::class)
        ->callAction(
            TestAction::make('return')->table($step),
            data: ['reason' => 'Quantities do not match the BOM.'],
        );

    $step->refresh();

    expect($step->decision)->toBe(ApprovalDecision::Returned)
        ->and($step->remarks)->toBe('Quantities do not match the BOM.');
});
