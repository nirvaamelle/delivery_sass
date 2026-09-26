<?php

use App\Domain\Procurement\InspectionService;
use App\Domain\Procurement\InsufficientStockException;
use App\Domain\Procurement\StockMovementType;
use App\Domain\Procurement\StockService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Stock cards, issuance and physical counts — P1-10
|--------------------------------------------------------------------------
|
| A stock card is a ledger, not a counter. The same reasoning as
| project_cost_ledger in P0-13: a stored `quantity_on_hand` that anything can
| write is a number nobody can explain, and the question a storekeeper is
| actually asked — "where did the other forty bags go" — is answerable only from
| movements.
|
| So the balance is derived by summing movements, receipts are positive,
| issuances negative, and a physical count that disagrees posts an **adjustment
| movement** rather than overwriting the balance. The discrepancy stays on the
| record, which is the entire point of counting.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function stock(): StockService
{
    return app(StockService::class);
}

it('takes accepted goods into stock', function () {
    [$po, $report] = acceptedGoods();

    $card = stock()->receive($report->fresh(), User::factory()->create());

    expect($card->movements()->count())->toBe(1)
        ->and(stock()->onHand($card))->toBe('500.0000');
});

it('refuses to take uninspected goods into stock', function () {
    // Inspection is what says the material is acceptable. Stock that never
    // passed it is stock nobody vouched for.
    [, $report] = receivedGoods();

    expect(fn () => stock()->receive($report, User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('takes in only the accepted quantity, not what arrived', function () {
    [$po, $report] = receivedGoods('500.0000');

    app(InspectionService::class)->inspect($report, [
        [
            'receiving_report_line_id' => $report->lines()->sole()->getKey(),
            'quantity_accepted' => '480.0000',
            'quantity_rejected' => '20.0000',
            'rejection_reason' => 'Hardened in transit.',
        ],
    ], User::factory()->create());

    $card = stock()->receive($report->fresh(), User::factory()->create());

    expect(stock()->onHand($card))->toBe('480.0000');
});

it('issues material against a cost code', function () {
    // PLAN.md §1: every document carries the cost code. An issuance is where
    // material becomes project cost, so it cannot be uncoded.
    [$po, $report] = acceptedGoods();
    $card = stock()->receive($report->fresh(), User::factory()->create());

    $issuance = stock()->issue($card, '120.0000', $card->cost_code_id, User::factory()->create(), 'Ground floor pour');

    expect($issuance->number)->toStartWith('MI-2026-')
        ->and(stock()->onHand($card->fresh()))->toBe('380.0000');
});

it('refuses to issue more than is on hand', function () {
    // A negative balance is a fiction. If the card says 500 and the site wants
    // 600, either the count is wrong or the request is — and both need a human.
    [$po, $report] = acceptedGoods();
    $card = stock()->receive($report->fresh(), User::factory()->create());

    expect(fn () => stock()->issue($card, '600.0000', $card->cost_code_id, User::factory()->create()))
        ->toThrow(InsufficientStockException::class);
});

it('refuses a cumulative over-issue', function () {
    [$po, $report] = acceptedGoods();
    $card = stock()->receive($report->fresh(), User::factory()->create());

    stock()->issue($card, '300.0000', $card->cost_code_id, User::factory()->create());

    expect(fn () => stock()->issue($card->fresh(), '300.0000', $card->cost_code_id, User::factory()->create()))
        ->toThrow(InsufficientStockException::class);
});

it('derives the balance from movements rather than a stored counter', function () {
    // Three movements, one balance. The card holds no quantity column at all —
    // it cannot drift from its own history.
    [$po, $report] = acceptedGoods();
    $card = stock()->receive($report->fresh(), User::factory()->create());

    stock()->issue($card, '100.0000', $card->cost_code_id, User::factory()->create());
    stock()->issue($card->fresh(), '50.0000', $card->cost_code_id, User::factory()->create());

    expect(stock()->onHand($card->fresh()))->toBe('350.0000')
        ->and($card->movements()->count())->toBe(3);
});

it('posts an adjustment when a physical count disagrees', function () {
    // The count does not overwrite the balance. It posts the difference as its
    // own movement, so the discrepancy survives on the record — which is the
    // entire reason for counting.
    [$po, $report] = acceptedGoods();
    $card = stock()->receive($report->fresh(), User::factory()->create());

    $count = stock()->count($card, '470.0000', User::factory()->create(), 'Quarterly count.');

    expect($count->variance)->toBe('-30.0000')
        ->and(stock()->onHand($card->fresh()))->toBe('470.0000')
        ->and($card->movements()->where('type', StockMovementType::Adjustment)->count())->toBe(1);
});

it('records a count that agrees without inventing a movement', function () {
    // A zero-variance count is still evidence that somebody counted, but it has
    // no effect on the balance and must not add noise to the card.
    [$po, $report] = acceptedGoods();
    $card = stock()->receive($report->fresh(), User::factory()->create());

    $count = stock()->count($card, '500.0000', User::factory()->create());

    expect($count->variance)->toBe('0.0000')
        ->and($card->movements()->count())->toBe(1);
});

it('refuses a movement quantity changed by a direct update', function () {
    [$po, $report] = acceptedGoods();
    $card = stock()->receive($report->fresh(), User::factory()->create());

    $movement = $card->movements()->sole();

    expect(fn () => $movement->update(['quantity' => '1.0000']))
        ->toThrow(DomainException::class);
});
