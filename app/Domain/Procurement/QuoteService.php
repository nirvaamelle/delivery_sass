<?php

namespace App\Domain\Procurement;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\Vendor;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recording what vendors quoted.
 *
 * Two rules that look like bookkeeping but are really controls:
 *
 * **Only invited vendors may quote.** An uninvited quote is one nobody
 * solicited, and it would sit in the abstract of canvass looking like part of
 * the comparison — which is a way to introduce a chosen supplier after the
 * solicitation closed.
 *
 * **The total is derived, never accepted.** It is the number the award is
 * decided on, so it is computed from the lines in bcmath and guarded on the
 * model. A total that does not match the lines printed beneath it is the sort
 * of discrepancy an auditor finds and nobody can explain.
 */
class QuoteService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
    ) {}

    /**
     * Record a vendor's quote against an issued RFQ.
     *
     * @param  array<int, array{description: string, quantity: string, unit_price: string, unit?: string}>  $lines
     *
     * @throws DomainException when the RFQ is not issued, or the vendor was not invited
     * @throws InvalidArgumentException when the quote has no lines
     */
    public function record(Rfq $rfq, Vendor $vendor, array $lines, ?string $remarks = null): Quote
    {
        if ($rfq->status !== RfqStatus::Issued) {
            throw new DomainException(sprintf(
                'RFQ %s is %s. A quote can only answer a solicitation that was actually sent.',
                $rfq->number,
                $rfq->status->value,
            ));
        }

        $invited = $rfq->recipients()->where('vendor_id', $vendor->getKey())->exists();

        if (! $invited) {
            throw new DomainException(sprintf(
                'Vendor %s was not invited to RFQ %s, so its quote is not part of this canvass.',
                $vendor->code,
                $rfq->number,
            ));
        }

        if ($lines === []) {
            throw new InvalidArgumentException('A quote with no lines prices nothing.');
        }

        return DB::transaction(function () use ($rfq, $vendor, $lines, $remarks): Quote {
            $quote = Quote::mutate(fn (): Quote => Quote::query()->create([
                'rfq_id' => $rfq->getKey(),
                'vendor_id' => $vendor->getKey(),
                'number' => $this->numbering->next('QUO'),
                'total_amount' => '0.0000',
                'quoted_at' => now(),
                'remarks' => $remarks,
            ]));

            $total = '0';

            foreach ($lines as $line) {
                // Quantity carries four places because partial units are real
                // in construction — 12.5 tonnes, 0.75 cubic metres — and
                // rounding one to a whole number changes the amount payable.
                // Money::multiply, not bcmul at scale 4. bcmul truncates, and
                // a line of 12.5 x 1,499.9999 is exactly 18,749.99875 - the
                // half is lost, systematically and always downward.
                $lineTotal = Money::multiply($line['quantity'], $line['unit_price']);

                $quote->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $lineTotal,
                    'unit' => $line['unit'] ?? null,
                ]);

                $total = Money::sum($total, $lineTotal);
            }

            Quote::mutate(fn () => $quote->update([
                'total_amount' => Money::sum($total),
            ]));

            return $quote->refresh();
        });
    }
}
