<?php

use App\Domain\Cutoffs\CutoffClosedException;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\MaterialCostPoster;
use App\Domain\Procurement\StockService;
use App\Models\CostCode;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Material cost reaches the ledger — P1-15
|--------------------------------------------------------------------------
|
| PLAN.md §1: every process ends in `project_cost_ledger`. Phase 1's exit gate
| says it plainly — "material cost appears in the ledger" — and until this task
| the procurement chain stopped one document short of it.
|
| **Cost enters the ledger when material is ISSUED, not when it is bought.**
| Bought material is inventory: it is an asset sitting in a yard, and charging it
| to a project on the day the invoice matched would cost the project for bags of
| cement nobody has opened. Issuance is the moment it becomes the project's, and
| it is the moment the deck's cost-per-unit figure depends on.
|
| That makes the valuation question real, and it is why stock movements now carry
| a unit cost. A card that tracks quantity but not value cannot say what the yard
| is worth, and cannot value an issue at anything better than a guess.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function materialPoster(): MaterialCostPoster
{
    return app(MaterialCostPoster::class);
}

it('values a receipt at the price on the purchase order', function () {
    // The card knows what the yard is worth, not just how full it is.
    [$card] = stockedGoods();

    expect(app(StockService::class)->valueOnHand($card))->toBe('124500.0000')
        ->and(app(StockService::class)->averageCost($card))->toBe('249.0000');
});

it('posts material cost to the ledger when material is issued', function () {
    // The exit gate's last clause, and the first time the procurement chain
    // reaches PLAN.md §1's organising table.
    [$card, $project, $costCode] = stockedGoods();

    $issuance = app(StockService::class)->issue($card, '100.0000', $costCode->getKey(), User::factory()->create());

    $entry = materialPoster()->post($issuance->fresh());

    expect($entry->category)->toBe(LedgerCategory::Material)
        ->and($entry->amount)->toBe('24900.0000')
        ->and($entry->project_code)->toBe($project->code)
        ->and($entry->cost_code)->toBe($costCode->code)
        ->and($entry->document_number)->toBe($issuance->number);
});

it('values an issue at the weighted average of what was received', function () {
    // Two receipts at different prices. 500 at 249 and 100 at 300 is 154,500
    // over 600 units — 257.50 each. Issuing at the latest price would make the
    // cost per unit depend on the order the trucks arrived in.
    [$card, $project, $costCode] = stockedGoods();

    app(StockService::class)->receiveAt($card, '100.0000', '300.0000', User::factory()->create());

    expect(app(StockService::class)->averageCost($card->fresh()))->toBe('257.5000');

    $issuance = app(StockService::class)->issue($card->fresh(), '100.0000', $costCode->getKey());
    $entry = materialPoster()->post($issuance->fresh());

    expect($entry->amount)->toBe('25750.0000');
});

it('refuses to post the same issuance twice', function () {
    // A double posting is a project charged twice for one issue, and the ledger
    // is append-only — the correction is a reversing entry, not a delete.
    [$card, $project, $costCode] = stockedGoods();
    $issuance = app(StockService::class)->issue($card, '100.0000', $costCode->getKey());

    materialPoster()->post($issuance->fresh());

    expect(fn () => materialPoster()->post($issuance->fresh()))
        ->toThrow(DomainException::class);
});

it('refuses to post material cost after the period has closed', function () {
    // F3's control, reached from the procurement chain for the first time. The
    // issue is dated inside May; the billing period for May closed on the 10th
    // of June, and today is later than that.
    [$card, $project, $costCode] = stockedGoods(cutoff: '2026-05-13 17:00:00');
    $issuance = app(StockService::class)->issue($card, '100.0000', $costCode->getKey());

    expect(fn () => materialPoster()->post($issuance->fresh()))
        ->toThrow(CutoffClosedException::class);
});

it('leaves the issuance unposted when the ledger refuses it', function () {
    // The posting reference is written in the same transaction as the entry. If
    // it were written first, a refused posting would leave an issuance that
    // claims to be in the ledger and is not.
    [$card, $project, $costCode] = stockedGoods(cutoff: '2026-05-13 17:00:00');
    $issuance = app(StockService::class)->issue($card, '100.0000', $costCode->getKey());

    try {
        materialPoster()->post($issuance->fresh());
    } catch (CutoffClosedException) {
        // expected
    }

    expect($issuance->fresh()->project_cost_ledger_entry_id)->toBeNull()
        ->and($issuance->fresh()->posted_at)->toBeNull();
});

it('takes the issued value out of the stock on hand', function () {
    // Value and quantity move together, or the yard's worth drifts from what is
    // in it — and the drift is invisible until somebody counts.
    [$card, $project, $costCode] = stockedGoods();

    app(StockService::class)->issue($card, '100.0000', $costCode->getKey());

    expect(app(StockService::class)->onHand($card->fresh()))->toBe('400.0000')
        ->and(app(StockService::class)->valueOnHand($card->fresh()))->toBe('99600.0000');
});

it('posts the material against the cost code the issue names, not the order', function () {
    // The PO bought cement for the project; the issue says which part of the
    // works it went into. PLAN.md §1 wants both on the row, and the cost code
    // that matters for cost-per-unit is the issue's.
    [$card, $project] = stockedGoods();
    $issueCode = CostCode::factory()->create([
        'organization_id' => $project->organization_id,
        'code' => '03.20.400',
    ]);

    $issuance = app(StockService::class)->issue($card, '50.0000', $issueCode->getKey());
    $entry = materialPoster()->post($issuance->fresh());

    expect($entry->cost_code)->toBe('03.20.400');
});
