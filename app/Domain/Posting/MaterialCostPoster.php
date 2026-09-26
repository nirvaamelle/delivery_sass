<?php

namespace App\Domain\Posting;

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Support\Money;
use App\Models\MaterialIssuance;
use App\Models\ProjectCostLedgerEntry;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Material cost reaching `project_cost_ledger` — P1-15, and the last clause of
 * Phase 1's exit gate.
 *
 * PLAN.md §1 calls the ledger the organising principle: every process ends
 * there. Until this service, the procurement chain stopped one document short.
 *
 * **The cost is posted at ISSUANCE, not at purchase.** Bought material is
 * inventory — an asset in a yard — and charging it to a project when the invoice
 * matched would cost the project for cement nobody has opened, in a month the
 * works had not reached. Issuance is the moment it becomes the project's, and it
 * is the moment the deck's cost-per-unit figure is built from.
 *
 * The cost code is the **issue's**, not the order's. The purchase order says
 * what was bought for the project; the issue says which part of the works it
 * went into, and that is the code cost per unit is grouped by.
 *
 * Nothing is written directly here. P0-13 made `LedgerPoster` the only code
 * permitted to write the ledger, and this service is a caller like any other —
 * which is why the cutoff check, the cross-organization check and the
 * append-only guarantee all still apply without being restated.
 */
class MaterialCostPoster
{
    public function __construct(
        private readonly LedgerPoster $ledger,
    ) {}

    /**
     * Post one issuance to the ledger.
     *
     * @throws DomainException when the issuance has already been posted, or has
     *                         no value to post
     */
    public function post(MaterialIssuance $issuance, ?string $description = null): ProjectCostLedgerEntry
    {
        if ($issuance->project_cost_ledger_entry_id !== null) {
            throw new DomainException(sprintf(
                'Material issuance %s is already in the ledger as entry %d. A second posting charges the project twice for one issue, and the ledger is append-only — the correction is a reversing entry.',
                $issuance->number,
                $issuance->project_cost_ledger_entry_id,
            ));
        }

        $card = $issuance->stockCard()->sole();
        $project = $card->project()->sole();
        $costCode = $issuance->costCode()->sole();

        $amount = $this->valueOf($issuance);

        if (Money::isZero($amount)) {
            throw new DomainException(sprintf(
                'Material issuance %s values at zero. An issue with no cost behind it means the receipts it came from carry no price, and posting it would put a free bag of cement into the project P&L.',
                $issuance->number,
            ));
        }

        return DB::transaction(function () use ($issuance, $card, $project, $costCode, $amount, $description): ProjectCostLedgerEntry {
            $entry = $this->ledger->post(
                project: $project,
                costCode: $costCode,
                category: LedgerCategory::Material,
                amount: $amount,
                sourceDocument: $issuance,
                documentNumber: $issuance->number,
                /*
                 * The BILLING calendar, not OPEX. Material issued to the works
                 * is direct project cost and belongs to the month the works
                 * consumed it, which is the month the client is billed for.
                 * OPEX cuts off on day 26 and governs operating expense, which
                 * this is not.
                 */
                cutoffType: CutoffType::Billing,
                documentDate: $issuance->issued_at,
                description: $description ?? sprintf('Material issued: %s', $card->item_description),
            );

            // Written inside the same transaction as the posting. Stamped first
            // and posted second, a refused posting would leave an issuance
            // claiming to be in a ledger that never took it.
            $issuance->update([
                'posted_at' => now(),
                'project_cost_ledger_entry_id' => $entry->getKey(),
            ]);

            return $entry;
        });
    }

    /**
     * What the issue was worth, at the cost frozen onto its movement.
     *
     * Read from the movement rather than recomputed from the card: a receipt
     * arriving next week at a different price must not restate what this issue
     * cost the project last month.
     */
    public function valueOf(MaterialIssuance $issuance): string
    {
        $movement = $issuance->stockCard()->sole()
            ->movements()
            ->where('source_document_type', $issuance->getMorphClass())
            ->where('source_document_id', $issuance->getKey())
            ->first();

        if ($movement === null || $movement->unit_cost === null) {
            return '0.0000';
        }

        // The movement's quantity is negative — stock left the yard — and cost
        // is positive, so the sign is taken off here rather than by negating a
        // number somebody has to remember is already negative.
        return Money::multiply((string) $issuance->quantity, (string) $movement->unit_cost);
    }
}
