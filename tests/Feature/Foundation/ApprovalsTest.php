<?php

use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Approvals\ApproverLacksAuthorityException;
use App\Domain\Approvals\NoApprovalTierException;
use App\Domain\Approvals\OverlappingTierException;
use App\Models\Budget;
use App\Models\User;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Approvals — P0-12
|--------------------------------------------------------------------------
|
| The last of PLAN.md §3's four cross-cutting services: read the authority
| matrix (slide 5's four tiers), route to approvers, record who approved what
| and when, and escalate a sole-source purchase one level above.
|
| PLACEHOLDER: Part D item 1 — the deck shows BANDS, not amounts, deliberately.
| The bands below are the build's own placeholders. This is B2 on the schedule's
| critical path: every approval routing test stays provisional until the real
| peso limits land, which is why the SHAPE of the routing is what is asserted
| here rather than any particular threshold.
|
*/

function router(): ApprovalRouter
{
    return app(ApprovalRouter::class);
}

function fourTiers(string $documentType = 'purchase_order'): void
{
    // Tier 1 carries ONE approver on purpose. PLAN.md §9 names approval fatigue
    // at Tier 1 as a risk and its mitigation is explicit: Tier 1 stays a
    // single-canvass, one-working-day path — do not add signatures to it.
    router()->defineTier($documentType, 1, '0.0000', '50000.0000', ['project-manager'], ['canvass']);
    router()->defineTier($documentType, 2, '50000.0001', '500000.0000', ['project-manager', 'procurement-head'], ['canvass', 'abstract-of-canvass']);
    router()->defineTier($documentType, 3, '500000.0001', '5000000.0000', ['procurement-head', 'finance-manager'], ['abstract-of-canvass', 'bid-tabulation']);
    router()->defineTier($documentType, 4, '5000000.0001', null, ['finance-manager', 'managing-director'], ['abstract-of-canvass', 'bid-tabulation', 'board-approval']);
}

it('routes an amount to the tier whose band contains it', function () {
    fourTiers();

    expect(router()->tierFor('purchase_order', '25000.0000')->tier)->toBe(1)
        ->and(router()->tierFor('purchase_order', '250000.0000')->tier)->toBe(2)
        ->and(router()->tierFor('purchase_order', '2500000.0000')->tier)->toBe(3)
        ->and(router()->tierFor('purchase_order', '25000000.0000')->tier)->toBe(4);
});

it('routes an amount sitting exactly on a band boundary', function () {
    // Off-by-one at a threshold is how a purchase gets approved one level too
    // low, which is the failure this whole matrix exists to prevent.
    fourTiers();

    expect(router()->tierFor('purchase_order', '50000.0000')->tier)->toBe(1)
        ->and(router()->tierFor('purchase_order', '50000.0001')->tier)->toBe(2);
});

it('rejects an amount that falls into no tier', function () {
    router()->defineTier('purchase_order', 1, '0.0000', '50000.0000', ['project-manager'], []);

    expect(fn () => router()->tierFor('purchase_order', '90000.0000'))
        ->toThrow(NoApprovalTierException::class);
});

it('rejects a tier whose band overlaps one already defined', function () {
    // Overlapping bands make routing ambiguous, and an ambiguous authority
    // matrix is worse than none — it looks authoritative and is not.
    router()->defineTier('purchase_order', 1, '0.0000', '50000.0000', ['project-manager'], []);

    expect(fn () => router()->defineTier('purchase_order', 2, '40000.0000', '500000.0000', ['procurement-head'], []))
        ->toThrow(OverlappingTierException::class);
});

it('escalates a sole-source purchase one tier above its amount', function () {
    // Slide 5: sole source needs written justification approved one level up.
    fourTiers();

    expect(router()->routeFor('purchase_order', '25000.0000', soleSource: false)->tier)->toBe(1)
        ->and(router()->routeFor('purchase_order', '25000.0000', soleSource: true)->tier)->toBe(2);
});

it('keeps a top-tier sole source at the top tier', function () {
    // There is no level above the top one. Staying is the only coherent answer,
    // and it is stated here so it is a decision rather than an accident.
    fourTiers();

    expect(router()->routeFor('purchase_order', '25000000.0000', soleSource: true)->tier)->toBe(4);
});

it('returns the approver roles for a tier in order', function () {
    fourTiers();

    expect(router()->tierFor('purchase_order', '250000.0000')->approver_roles)
        ->toBe(['project-manager', 'procurement-head']);
});

it('exposes the document set a tier requires', function () {
    fourTiers();

    expect(router()->tierFor('purchase_order', '2500000.0000')->required_documents)
        ->toBe(['abstract-of-canvass', 'bid-tabulation']);
});

it('opens one pending approval step per approver role, in order', function () {
    fourTiers();
    $budget = Budget::factory()->create();

    $steps = router()->request($budget, 'purchase_order', '250000.0000');

    expect($steps)->toHaveCount(2)
        ->and($steps->pluck('approver_role')->all())->toBe(['project-manager', 'procurement-head'])
        ->and($steps->pluck('step')->all())->toBe([1, 2])
        ->and($steps->pluck('decision')->unique()->all())->toBe([ApprovalDecision::Pending]);
});

it('records who approved and when', function () {
    fourTiers();
    Role::create(['name' => 'project-manager']);

    $approver = User::factory()->create();
    $approver->assignRole('project-manager');

    $budget = Budget::factory()->create();
    $step = router()->request($budget, 'purchase_order', '25000.0000')->first();

    $decided = router()->approve($step, $approver, 'Within budget.');

    expect($decided->decision)->toBe(ApprovalDecision::Approved)
        ->and($decided->approver_user_id)->toBe($approver->id)
        ->and($decided->decided_at)->not->toBeNull()
        ->and($decided->remarks)->toBe('Within budget.');
});

it('rejects an approval by someone without the tier authority', function () {
    // The matrix is not advisory. A user who does not hold the role the tier
    // names cannot approve at that tier, whatever the UI offered them.
    fourTiers();
    Role::create(['name' => 'project-manager']);

    $stranger = User::factory()->create();
    $budget = Budget::factory()->create();
    $step = router()->request($budget, 'purchase_order', '25000.0000')->first();

    expect(fn () => router()->approve($step, $stranger))
        ->toThrow(ApproverLacksAuthorityException::class);
});

it('records the reason when an approval is returned', function () {
    // The deck's approved-or-returned branch: a returned document carries why,
    // because the reason is what stops the same item being billed twice.
    fourTiers();
    Role::create(['name' => 'project-manager']);

    $approver = User::factory()->create();
    $approver->assignRole('project-manager');

    $budget = Budget::factory()->create();
    $step = router()->request($budget, 'purchase_order', '25000.0000')->first();

    $returned = router()->returnForRevision($step, $approver, 'Quantities do not match the BOM.');

    expect($returned->decision)->toBe(ApprovalDecision::Returned)
        ->and($returned->remarks)->toBe('Quantities do not match the BOM.');
});
