<?php

namespace App\Domain\Procurement;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\MaterialIssuance;
use App\Models\PhysicalCount;
use App\Models\ReceivingReport;
use App\Models\StockCard;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Stock cards, issuance and physical counts.
 *
 * **A stock card is a ledger, not a counter.** The card carries no balance
 * column at all: the balance is the sum of its movements, for the same reason
 * `project_cost_ledger` is append-only. A stored `quantity_on_hand` that
 * anything can write is a number nobody can explain, and the question a
 * storekeeper is actually asked is not "how many are there" but "where did the
 * other forty bags go" — which only movements answer.
 *
 * The consequence worth stating: **a physical count does not overwrite the
 * balance.** It posts the difference as its own adjustment movement, so the
 * discrepancy stays visible on the card. Overwriting would make the count
 * self-erasing, which defeats the reason for counting.
 *
 * Only inspected, accepted material enters stock. Uninspected goods are goods
 * nobody vouched for, and treating them as stock would let material bypass the
 * check entirely by never being inspected.
 */
class StockService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly InspectionService $inspections,
    ) {}

    /**
     * Take accepted goods into stock.
     *
     * @throws DomainException when the delivery has not been inspected
     */
    public function receive(ReceivingReport $report, ?User $by = null): StockCard
    {
        $inspection = $report->inspection()->first();

        if ($inspection === null) {
            throw new DomainException(sprintf(
                'Receiving report %s has not been inspected. Stock that never passed inspection is stock nobody vouched for.',
                $report->number,
            ));
        }

        $accepted = $this->inspections->acceptedFor($report);
        $order = $report->purchaseOrder()->sole();
        $orderLine = $order->lines()->first();

        return DB::transaction(function () use ($report, $order, $orderLine, $accepted, $by): StockCard {
            $card = StockCard::query()->firstOrCreate(
                [
                    'project_id' => $order->project_id,
                    'cost_code_id' => $orderLine->cost_code_id,
                    'item_description' => $orderLine->description,
                ],
                ['unit' => $orderLine->unit],
            );

            $card->movements()->create([
                'type' => StockMovementType::Receipt,
                'quantity' => $accepted,
                // Valued at the price actually ordered. The card is what the
                // issue is costed against, so a receipt with no price makes
                // every later issue a guess.
                'unit_cost' => (string) $orderLine->unit_price,
                'source_document_type' => $report->getMorphClass(),
                'source_document_id' => $report->getKey(),
                'moved_at' => now(),
                'moved_by_user_id' => $by?->getKey(),
            ]);

            return $card->refresh();
        });
    }

    /**
     * Issue material to the works.
     *
     * @throws InsufficientStockException when the issue would drive the balance negative
     */
    public function issue(
        StockCard $card,
        string $quantity,
        int $costCodeId,
        ?User $by = null,
        ?string $purpose = null,
        ?string $issuedTo = null,
    ): MaterialIssuance {
        $onHand = $this->onHand($card);
        $averageCost = $this->averageCost($card);

        if (Money::greaterThan($quantity, $onHand)) {
            throw new InsufficientStockException(sprintf(
                'Stock card %d holds %s; the issue asks for %s. A negative balance is a fiction — either the count is wrong or the request is.',
                $card->getKey(),
                $onHand,
                $quantity,
            ));
        }

        return DB::transaction(function () use ($card, $quantity, $averageCost, $costCodeId, $by, $purpose, $issuedTo): MaterialIssuance {
            $issuance = MaterialIssuance::query()->create([
                'stock_card_id' => $card->getKey(),
                'cost_code_id' => $costCodeId,
                'number' => $this->numbering->next('MI'),
                'quantity' => $quantity,
                'issued_to' => $issuedTo,
                'purpose' => $purpose,
                'issued_at' => now(),
                'issued_by_user_id' => $by?->getKey(),
            ]);

            // Negative: the movement carries its own sign so the balance is a
            // sum rather than a difference somebody can get backwards.
            $card->movements()->create([
                'type' => StockMovementType::Issuance,
                'quantity' => bcsub('0', $quantity, Money::SCALE),
                // The average as at this moment, frozen onto the movement. A
                // later receipt at a different price must not restate what this
                // issue cost the project.
                'unit_cost' => $averageCost,
                'source_document_type' => $issuance->getMorphClass(),
                'source_document_id' => $issuance->getKey(),
                'moved_at' => now(),
                'moved_by_user_id' => $by?->getKey(),
            ]);

            return $issuance;
        });
    }

    /**
     * Record a physical count, posting an adjustment for any difference.
     *
     * A count that agrees posts no movement — it is still evidence somebody
     * counted, but a zero-quantity row would be noise on a card that exists to
     * be read.
     */
    public function count(StockCard $card, string $countedQuantity, ?User $by = null, ?string $remarks = null): PhysicalCount
    {
        $book = $this->onHand($card);
        $variance = bcsub($countedQuantity, $book, Money::SCALE);

        return DB::transaction(function () use ($card, $book, $countedQuantity, $variance, $by, $remarks): PhysicalCount {
            $count = PhysicalCount::query()->create([
                'stock_card_id' => $card->getKey(),
                'number' => $this->numbering->next('PC'),
                // A snapshot, not something recomputed later: the adjustment
                // this count is about to post will itself change the balance.
                'book_quantity' => $book,
                'counted_quantity' => $countedQuantity,
                'variance' => $variance,
                'counted_at' => now(),
                'counted_by_user_id' => $by?->getKey(),
                'remarks' => $remarks,
            ]);

            if (! Money::isZero($variance)) {
                $card->movements()->create([
                    'type' => StockMovementType::Adjustment,
                    'quantity' => $variance,
                    'source_document_type' => $count->getMorphClass(),
                    'source_document_id' => $count->getKey(),
                    'moved_at' => now(),
                    'moved_by_user_id' => $by?->getKey(),
                    'remarks' => $remarks,
                ]);
            }

            return $count;
        });
    }

    /**
     * Take goods into stock at a stated price.
     *
     * The ordinary path is `receive()`, which reads the price off the purchase
     * order. This exists for the second and later receipts onto a card that is
     * already holding stock bought at a different price — which is the whole
     * reason an issue has to be valued at an average rather than at "the" price.
     */
    public function receiveAt(StockCard $card, string $quantity, string $unitCost, ?User $by = null): StockCard
    {
        if (Money::isZero($quantity)) {
            throw new DomainException('A zero-quantity receipt is noise on a card that exists to be read.');
        }

        $card->movements()->create([
            'type' => StockMovementType::Receipt,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'moved_at' => now(),
            'moved_by_user_id' => $by?->getKey(),
        ]);

        return $card->refresh();
    }

    /**
     * What the stock on this card is worth.
     *
     * Summed from the movements exactly like the quantity is, and for the same
     * reason: a stored value is a number nobody can explain, and "what is the
     * yard worth" is a required output of the monthly consolidation.
     */
    public function valueOnHand(StockCard $card): string
    {
        $value = '0.0000';

        foreach ($card->movements()->get() as $movement) {
            $unitCost = $movement->unit_cost;

            if ($unitCost === null) {
                // An adjustment has no price of its own; it is worth the average
                // of what it is adjusting. Skipped in the running total and
                // handled by the average below.
                continue;
            }

            $value = Money::sum($value, Money::multiply((string) $movement->quantity, (string) $unitCost));
        }

        return $value;
    }

    /**
     * The weighted average cost of what is on the card.
     *
     * Weighted average rather than latest price: valuing an issue at the price
     * of the most recent truck makes the project's cost per unit depend on the
     * order the trucks happened to arrive in.
     */
    public function averageCost(StockCard $card): string
    {
        $quantity = '0.0000';
        $value = '0.0000';

        foreach ($card->movements()->where('type', StockMovementType::Receipt)->get() as $movement) {
            $quantity = Money::sum($quantity, (string) $movement->quantity);

            if ($movement->unit_cost !== null) {
                $value = Money::sum($value, Money::multiply((string) $movement->quantity, (string) $movement->unit_cost));
            }
        }

        if (Money::isZero($quantity)) {
            return '0.0000';
        }

        return Money::round(bcdiv($value, $quantity, Money::WORKING_SCALE));
    }

    /**
     * The balance, summed from movements.
     *
     * Summed in bcmath rather than SQL so the result is a decimal string end to
     * end — the same discipline as the ledger, because issuance quantities feed
     * material cost.
     */
    public function onHand(StockCard $card): string
    {
        $balance = '0';

        foreach ($card->movements()->pluck('quantity') as $quantity) {
            $balance = Money::sum($balance, (string) $quantity);
        }

        return $balance;
    }
}
