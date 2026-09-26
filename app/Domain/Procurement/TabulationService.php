<?php

namespace App\Domain\Procurement;

use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Models\BidTabulation;
use App\Models\Quote;
use App\Models\Rfq;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The abstract of canvass — PLAN.md §5's three-quote minimum, on the answers.
 *
 * P1-03 enforced the minimum on who was *asked*. This enforces it on who
 * *answered*, and the difference is the entire control: inviting three vendors
 * and receiving one quote is not a canvass. A comparison of one is a decision
 * that was already made, and the paperwork is being assembled afterwards to
 * describe it.
 *
 * **Recommending is not awarding.** This service picks the lowest responsive
 * quote and records what it compared; the award is the tiered approval in
 * P1-06. Keeping them apart is what stops "the system chose it" standing in for
 * a signature — the deck's authority matrix exists because somebody has to own
 * the decision.
 */
class TabulationService
{
    /**
     * PLAN.md §5: three quotes minimum before award.
     */
    private const MINIMUM_QUOTES = 3;

    private const SCALE = 4;

    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly DocumentLinker $links,
    ) {}

    /**
     * Tabulate the quotes received and recommend the lowest.
     *
     * @throws InsufficientQuotesException when fewer than three quotes came back
     *                                     and the RFQ is not a declared sole source
     * @throws DomainException when the RFQ has already been tabulated
     */
    public function tabulate(Rfq $rfq, ?string $remarks = null): BidTabulation
    {
        if ($rfq->tabulation()->exists()) {
            throw new DomainException(sprintf(
                'RFQ %s has already been tabulated. A second recommendation for one solicitation leaves nothing to say which the award followed.',
                $rfq->number,
            ));
        }

        $quotes = $rfq->quotes()->orderBy('total_amount')->get();

        if ($quotes->isEmpty()) {
            throw new InsufficientQuotesException(sprintf(
                'RFQ %s received no quotes; there is nothing to compare.',
                $rfq->number,
            ));
        }

        // Sole source is the documented exception. It was declared when the RFQ
        // was opened and carries a written justification approved one level up
        // (P1-05) — it is not "fewer than three, after the fact".
        if (! $rfq->sole_source && $quotes->count() < self::MINIMUM_QUOTES) {
            throw new InsufficientQuotesException(sprintf(
                'RFQ %s received %d quote(s); PLAN.md §5 requires %d before award. Inviting three and hearing from one is not a canvass.',
                $rfq->number,
                $quotes->count(),
                self::MINIMUM_QUOTES,
            ));
        }

        $lowest = $this->lowest($quotes);

        return DB::transaction(function () use ($rfq, $quotes, $lowest, $remarks): BidTabulation {
            $tabulation = BidTabulation::mutate(fn (): BidTabulation => BidTabulation::query()->create([
                'rfq_id' => $rfq->getKey(),
                'number' => $this->numbering->next('ABC'),
                'recommended_vendor_id' => $lowest->vendor_id,
                'recommended_amount' => $lowest->total_amount,

                // Stored as evidence rather than recomputed later. Quotes can be
                // added to an RFQ afterwards, so counting today's rows answers a
                // different question than "what was on the table when this was
                // decided".
                'quotes_compared' => $quotes->count(),
                'sole_source' => $rfq->sole_source,
                'tabulated_at' => now(),
                'tabulated_by_user_id' => auth()->id(),
                'remarks' => $remarks,
            ]));

            $this->links->link($rfq, $tabulation);

            return $tabulation->refresh();
        });
    }

    /**
     * The lowest quote, compared in bcmath.
     *
     * Ordering in SQL would already be correct on DECIMAL columns, but the
     * winner is chosen here explicitly so the comparison that decides an award
     * is one line of readable code rather than an implicit property of a query.
     *
     * @param  Collection<int, Quote>  $quotes
     */
    private function lowest(Collection $quotes): Quote
    {
        return $quotes->reduce(
            fn (?Quote $carry, Quote $quote): Quote => $carry === null
                || bccomp((string) $quote->total_amount, (string) $carry->total_amount, self::SCALE) < 0
                    ? $quote
                    : $carry,
        );
    }
}
