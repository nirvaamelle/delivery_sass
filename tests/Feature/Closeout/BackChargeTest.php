<?php

use App\Domain\Closeout\PunchlistResponsibility;
use App\Domain\Posting\CrossOrganizationPostingException;
use App\Domain\Posting\LedgerCategory;
use App\Models\BackCharge;
use App\Models\CostCode;
use App\Models\ProjectCostLedgerEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Subcontractor back-charges — P5-02, closes F6
|--------------------------------------------------------------------------
|
| F6: "Subcontractor back-charges appear in PLAN.md §6 Phase 5 prose but have no
| table in §4. They reduce a subcontractor's payable and increase project cost —
| they are LEDGER POSTINGS, and they gate the final billing amount."
|
| And PHASE-PLAN.md Part C: "computed at punchlist clearing, applied to final
| billing before the invoice is raised." The two verbs are the design.
|
| **Computed at clearing.** A back-charge is raised against a punchlist item that
| has been made good — not against an open one. The cost of a remedy nobody has
| carried out yet is an estimate, and an estimate posted to the ledger is a
| figure the P&L cannot distinguish from money actually spent.
|
| **Applied at final billing.** The recovery is NOT posted here, and that is the
| decision this suite exists to pin down. Posting both legs at raise time —
| the cost out and the recovery back — would book a credit nobody has collected
| and net the exposure to zero on the day it was incurred. What the project
| actually carries is the cost; what it MAY recover depends on how much of the
| subcontract is still unpaid when the settlement is made. So the cost is posted
| and the recovery is derived, which is how every balance in this build works.
|
| The residue is the number worth having: a back-charge larger than what is left
| owing under the subcontract cannot be recovered in full, and the difference is
| money the project eats. F6 calls it "increase project cost". It is visible here
| rather than discovered at final billing.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
    billingCalendarForMay();
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| Computed at punchlist clearing
|--------------------------------------------------------------------------
*/

it('raises a back-charge against a cleared subcontractor item', function () {
    [$item, $subcontract, $costCode] = clearedSubcontractorItem();

    $charge = backCharges()->raise($item, $costCode, '48250.0000', User::factory()->create(), 'Handrail re-set by own forces.');

    expect($charge->number)->toStartWith('BC-2026-')
        ->and($charge->amount)->toBe('48250.0000')
        ->and((int) $charge->subcontract_id)->toBe($subcontract->getKey())
        ->and((int) $charge->punchlist_item_id)->toBe($item->getKey());
});

it('refuses a back-charge on an item nobody has cleared', function () {
    // "Computed AT punchlist clearing." Until the defect is made good, the cost
    // of making it good is an estimate — and an estimate in the ledger is
    // indistinguishable from money that was spent.
    [$punchlist, $subcontract] = punchlistWithSubcontract();
    $open = punchlists()->addItem(
        $punchlist, 'Steel handrail out of plumb',
        responsibility: PunchlistResponsibility::Subcontractor,
        subcontract: $subcontract,
    );

    expect(fn () => backCharges()->raise($open, remedialCostCode($punchlist), '48250.0000', User::factory()->create(), 'x'))
        ->toThrow(DomainException::class, 'has not been cleared');
});

it('refuses a back-charge on an item our own forces were responsible for', function () {
    // There is nobody to charge. The cost is already in the project through
    // labour and materials, and charging it again would double it.
    $punchlist = issuedPunchlist();
    $ours = punchlists()->addItem($punchlist, 'Chipped tiles, lobby');
    punchlists()->clear($ours, User::factory()->create(), 'Replaced.');

    expect(fn () => backCharges()->raise($ours->fresh(), remedialCostCode($punchlist), '4000.0000', User::factory()->create(), 'x'))
        ->toThrow(DomainException::class, 'own forces');
});

it('refuses a second back-charge on one item, at the database', function () {
    // One defect, one charge. A second would bill the subcontractor twice for
    // the same handrail, and both rows would trace to the same punchlist line.
    [$item, $subcontract, $costCode] = clearedSubcontractorItem();
    backCharges()->raise($item, $costCode, '48250.0000', User::factory()->create(), 'Handrail re-set.');

    expect(fn () => BackCharge::query()->create([
        'punchlist_item_id' => $item->getKey(),
        'subcontract_id' => $subcontract->getKey(),
        'project_id' => $item->punchlist->project_id,
        'cost_code_id' => $costCode->getKey(),
        'number' => 'BC-2026-99999',
        'amount' => '1000.0000',
        'description' => 'Again',
        'raised_at' => now(),
        'raised_by_user_id' => User::factory()->create()->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses a back-charge of nothing', function () {
    [$item, , $costCode] = clearedSubcontractorItem();

    expect(fn () => backCharges()->raise($item, $costCode, '0.0000', User::factory()->create(), 'x'))
        ->toThrow(DomainException::class, 'positive amount');
});

it('refuses a negative back-charge at the database', function () {
    // A negative back-charge is a payment to the subcontractor wearing the
    // wrong document. Guarded past the service, as the ledger amounts are.
    [$item, $subcontract, $costCode] = clearedSubcontractorItem();

    expect(fn () => BackCharge::query()->create([
        'punchlist_item_id' => $item->getKey(),
        'subcontract_id' => $subcontract->getKey(),
        'project_id' => $item->punchlist->project_id,
        'cost_code_id' => $costCode->getKey(),
        'number' => 'BC-2026-99998',
        'amount' => '-5000.0000',
        'description' => 'Credit',
        'raised_at' => now(),
        'raised_by_user_id' => User::factory()->create()->getKey(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| They are ledger postings — F6's operative clause
|--------------------------------------------------------------------------
*/

it('puts the cost of making the defect good into the ledger', function () {
    [$item, , $costCode] = clearedSubcontractorItem();

    $charge = backCharges()->raise($item, $costCode, '48250.0000', User::factory()->create(), 'Handrail re-set by own forces.');

    $entry = ProjectCostLedgerEntry::query()
        ->where('source_document_type', $charge->getMorphClass())
        ->where('source_document_id', $charge->getKey())
        ->sole();

    expect($entry->category)->toBe(LedgerCategory::Subcontract)
        ->and($entry->amount)->toBe('48250.0000')
        ->and($entry->document_number)->toBe($charge->number)
        ->and((int) $charge->fresh()->project_cost_ledger_entry_id)->toBe($entry->getKey());
});

it('does not post the recovery, because nobody has recovered it yet', function () {
    // The decision this suite exists to pin down. A credit posted the day the
    // cost was incurred nets the exposure to zero before anybody has settled
    // with the subcontractor — and the settlement may not cover it.
    [$item, , $costCode] = clearedSubcontractorItem();

    $charge = backCharges()->raise($item, $costCode, '48250.0000', User::factory()->create(), 'Handrail re-set.');

    expect(ProjectCostLedgerEntry::query()
        ->where('source_document_type', $charge->getMorphClass())
        ->count())->toBe(1);
});

it('refuses to post against another organization cost code', function () {
    // The LedgerPoster's cross-organization guard, reached through this door
    // like any other. One company's remedy in another company's P&L.
    [$item] = clearedSubcontractorItem();
    $elsewhere = CostCode::factory()->create();

    expect(fn () => backCharges()->raise($item, $elsewhere, '48250.0000', User::factory()->create(), 'x'))
        ->toThrow(CrossOrganizationPostingException::class);
});

/*
|--------------------------------------------------------------------------
| Reducing the payable — derived, never stored
|--------------------------------------------------------------------------
*/

it('reduces what is payable under the subcontract', function () {
    [$item, $subcontract, $costCode] = clearedSubcontractorItem();

    expect(backCharges()->netPayable($subcontract))->toBe('2500000.0000');

    backCharges()->raise($item, $costCode, '48250.0000', User::factory()->create(), 'Handrail re-set.');

    expect(backCharges()->chargedAgainst($subcontract))->toBe('48250.0000')
        ->and(backCharges()->netPayable($subcontract))->toBe('2451750.0000')
        ->and(backCharges()->unrecoveredFrom($subcontract))->toBe('0.0000');
});

it('caps the recovery at what is left owing, and shows what the project eats', function () {
    // The residue F6 calls "increase project cost". A defect can genuinely cost
    // more to put right than the subcontract is worth; the difference is not
    // recoverable, and a system that netted it to zero would hide a real loss.
    [$item, $subcontract, $costCode] = clearedSubcontractorItem('80000.0000');

    backCharges()->raise($item, $costCode, '125000.0000', User::factory()->create(), 'Handrail re-set, full replacement.');

    expect(backCharges()->chargedAgainst($subcontract))->toBe('125000.0000')
        ->and(backCharges()->netPayable($subcontract))->toBe('0.0000')
        ->and(backCharges()->unrecoveredFrom($subcontract))->toBe('45000.0000');
});

it('sums back-charges per project, which is what final billing reads', function () {
    [$item, , $costCode] = clearedSubcontractorItem();
    $project = $item->punchlist->project;

    backCharges()->raise($item, $costCode, '48250.0000', User::factory()->create(), 'Handrail re-set.');

    expect(backCharges()->totalForProject($project))->toBe('48250.0000');
});

/*
|--------------------------------------------------------------------------
| Step 2 as a predecessor of step 4
|--------------------------------------------------------------------------
*/

it('names cleared subcontractor items that nobody has priced', function () {
    // Slide 9's structural obligation: "final billing depends on back-charges
    // being computed first, which makes step 2 a hard predecessor of step 4."
    // A cleared subcontractor defect with no charge against it is an open
    // question, and P5-05 is entitled to refuse to invoice over it.
    [$punchlist, $subcontract] = punchlistWithSubcontract();
    $qc = User::factory()->create();

    $priced = punchlists()->addItem($punchlist, 'Handrail out of plumb', responsibility: PunchlistResponsibility::Subcontractor, subcontract: $subcontract);
    $unpriced = punchlists()->addItem($punchlist, 'Grout missing, stair nosing', responsibility: PunchlistResponsibility::Subcontractor, subcontract: $subcontract);
    punchlists()->addItem($punchlist, 'Chipped tiles, lobby');

    foreach ([$priced, $unpriced] as $item) {
        punchlists()->clear($item, $qc, 'Made good.');
    }

    backCharges()->raise($priced->fresh(), remedialCostCode($punchlist), '48250.0000', $qc, 'Handrail re-set.');

    expect(backCharges()->unpricedItems($punchlist)->pluck('id')->all())
        ->toBe([$unpriced->getKey()]);
});

it('leaves nothing unpriced once every cleared subcontractor item is charged', function () {
    [$item, , $costCode] = clearedSubcontractorItem();

    expect(backCharges()->unpricedItems($item->punchlist)->pluck('id')->all())->toBe([$item->getKey()]);

    backCharges()->raise($item, $costCode, '48250.0000', User::factory()->create(), 'Handrail re-set.');

    expect(backCharges()->unpricedItems($item->punchlist->fresh()))->toBeEmpty();
});
