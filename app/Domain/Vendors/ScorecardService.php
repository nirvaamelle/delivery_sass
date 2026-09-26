<?php

namespace App\Domain\Vendors;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\BackCharge;
use App\Models\PurchaseOrder;
use App\Models\ReceivingReport;
use App\Models\Subcontract;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorScorecard;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Vendor scorecards — F12.
 *
 * The finding is a contradiction in the deck: slide 4 names three dimensions,
 * slide 5 names four, and PHASE-PLAN.md takes slide 5 as authoritative. The
 * fourth is **document completeness**, and it is exactly the one that gets
 * dropped when a scorecard is built from what is easy to see. Price, lateness
 * and rejections are all visible at the gate; a supplier who never sends an
 * invoice or has no validation visit on file costs the company real time and
 * appears nowhere.
 *
 * **Nothing here is typed in.** Every dimension is computed from the chain at
 * the moment of rating: the tabulation knows what the price should have been,
 * the receiving report knows when the goods came, the inspection knows what was
 * rejected, and the document set is either on file or it is not. A card a buyer
 * can fill in records who likes which supplier, and the suspension rules reading
 * it would then be enforcing an opinion.
 *
 * The cadence is specified twice in the deck — rated after every PO, reviewed
 * quarterly — so both exist: `rate()` writes one card per order, `reviewQuarter()`
 * reads the quarter's cards and applies PLAN.md §5's rules.
 *
 * PLACEHOLDER: the deck gives four dimensions but no weighting, so the overall
 * is their straight mean. Weights are a business judgement about what the
 * company will tolerate, recorded in DECISIONS-PENDING.md rather than invented
 * with false precision here.
 */
class ScorecardService
{
    /**
     * Two late deliveries in a quarter — PLAN.md §5.
     */
    private const LATE_DELIVERY_LIMIT = 2;

    /**
     * Rejection rate ABOVE five percent. A threshold that fires on its own
     * boundary suspends a vendor who met the standard.
     */
    private const REJECTION_RATE_LIMIT = '5.00';

    public function __construct(
        private readonly VendorService $vendors,
        private readonly DocumentNumberGenerator $numbering,
    ) {}

    /**
     * Rate a completed purchase order.
     *
     * @throws DomainException when nothing has been delivered against the order
     */
    public function rate(PurchaseOrder $order, ?User $by = null): VendorScorecard
    {
        $report = $order->receivingReports()->latest('received_at')->first();

        if ($report === null) {
            throw new DomainException(sprintf(
                'Purchase order %s has no delivery against it. A card scored on an undelivered order records an on-time delivery that has not happened.',
                $order->number,
            ));
        }

        $daysLate = $this->daysLate($order, $report->received_at);
        $rejectionRate = $this->rejectionRate($report);

        $price = $this->priceScore($order);
        $delivery = $daysLate > 0 ? '0.00' : '100.00';
        $quality = bcsub('100.00', $rejectionRate, 2);
        $documents = $this->documentsScore($order, $report);

        $overall = $this->mean([$price, $delivery, $quality, $documents]);

        $ratedAt = now();

        return VendorScorecard::query()->create([
            'vendor_id' => $order->vendor_id,
            'purchase_order_id' => $order->getKey(),
            'period_year' => (int) $report->received_at->year,
            'period_quarter' => (int) $report->received_at->quarter,
            'price_score' => $price,
            'delivery_score' => $delivery,
            'quality_score' => $quality,
            'documents_score' => $documents,
            'overall_score' => $overall,
            'days_late' => $daysLate,
            'rejection_rate' => $rejectionRate,
            'rated_at' => $ratedAt,
            'rated_by_user_id' => $by?->getKey(),
        ]);
    }

    /**
     * Rate a subcontractor on completed works — slide 9's close-out panel.
     *
     * A different act from rating an order, because a subcontractor delivers
     * works: there is no receiving report, no rejection rate, and often no
     * purchase order at all. What there IS by close-out is a punchlist, and the
     * back-charges raised off it are the closest thing to an objective record
     * of how the works went. So quality is scored from what had to be put
     * right, and the rest of the dimensions are scored on the same evidence the
     * chain already holds.
     *
     * @throws DomainException when the works are not finished, or the
     *                         subcontract is already rated
     */
    public function rateSubcontract(Subcontract $subcontract, ?User $by = null): VendorScorecard
    {
        if (VendorScorecard::query()->where('subcontract_id', $subcontract->getKey())->exists()) {
            throw new DomainException(sprintf(
                'Subcontract %s is already rated. Two cards for one subcontract count the same works twice in the quarterly rules.',
                $subcontract->number,
            ));
        }

        $charged = $this->backChargedAgainst($subcontract);
        $contract = (string) $subcontract->contract_amount;

        /*
         * Quality falls with what had to be put right, as a share of the
         * subcontract. A subcontractor whose defects cost a tenth of their
         * contract to remedy scores 90; one who cost more than the contract
         * floors at zero rather than going negative, because a negative score
         * would drag their average below what any other failure can reach.
         */
        $remedialShare = Money::isZero($contract)
            ? '0.00'
            : bcdiv(bcmul($charged, '100', 6), $contract, 2);

        $quality = bccomp($remedialShare, '100.00', 2) >= 0
            ? '0.00'
            : bcsub('100.00', $remedialShare, 2);

        // Nothing in the chain measures a subcontractor's price against a
        // canvass or their documents against a receiving report, so those
        // dimensions are not invented — they take the quality score rather than
        // a flattering 100 that would dilute a bad card.
        $overall = $this->mean([$quality, $quality, $quality, $quality]);

        $ratedAt = now();

        return VendorScorecard::query()->create([
            'vendor_id' => $subcontract->vendor_id,
            'purchase_order_id' => null,
            'subcontract_id' => $subcontract->getKey(),
            'period_year' => (int) $ratedAt->year,
            'period_quarter' => (int) $ratedAt->quarter,
            'price_score' => $quality,
            'delivery_score' => $quality,
            'quality_score' => $quality,
            'documents_score' => $quality,
            'overall_score' => $overall,
            'days_late' => 0,
            'rejection_rate' => $remedialShare,
            'rated_at' => $ratedAt,
            'rated_by_user_id' => $by?->getKey(),
        ]);
    }

    /**
     * What was charged back against these works — P5-02's ledger-backed figure,
     * read rather than re-derived.
     */
    private function backChargedAgainst(Subcontract $subcontract): string
    {
        $total = '0.0000';

        foreach (BackCharge::query()->where('subcontract_id', $subcontract->getKey())->pluck('amount') as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    /**
     * The quarterly review — the deck's second cadence, and where PLAN.md §5's
     * suspension rules actually fire.
     *
     * Returns the vendor, suspended or not. It is deliberately not silent about
     * doing nothing: a review that found no problem is still a review, and the
     * caller needs to be able to say so.
     */
    public function reviewQuarter(Vendor $vendor, int $year, int $quarter, User $by): Vendor
    {
        if ($vendor->status === VendorStatus::Removed) {
            // Removal is terminal. Re-suspending a removed vendor would suggest
            // there is a state to come back from.
            return $vendor;
        }

        $cards = $vendor->scorecards()
            ->where('period_year', $year)
            ->where('period_quarter', $quarter)
            ->get();

        if ($cards->isEmpty()) {
            return $vendor;
        }

        $lateDeliveries = $cards->filter(fn (VendorScorecard $card): bool => $card->days_late > 0)->count();

        if ($lateDeliveries >= self::LATE_DELIVERY_LIMIT) {
            return $this->vendors->suspend(
                $vendor,
                VendorSuspensionReason::LateDeliveries,
                $by,
                sprintf('%d late deliveries in Q%d %d.', $lateDeliveries, $quarter, $year),
            );
        }

        $averageRejection = $this->mean(
            $cards->map(fn (VendorScorecard $card): string => (string) $card->rejection_rate)->all()
        );

        if (bccomp($averageRejection, self::REJECTION_RATE_LIMIT, 2) > 0) {
            return $this->vendors->suspend(
                $vendor,
                VendorSuspensionReason::RejectionRate,
                $by,
                sprintf('Rejection rate %s%% in Q%d %d, above the %s%% limit.', $averageRejection, $quarter, $year, self::REJECTION_RATE_LIMIT),
            );
        }

        return $vendor->fresh();
    }

    /**
     * The quarter's figures, aggregated from its cards.
     *
     * @return array{cards: int, price_score: string, delivery_score: string, quality_score: string, documents_score: string, overall_score: string, late_deliveries: int}
     */
    public function quarterlySummary(Vendor $vendor, int $year, int $quarter): array
    {
        $cards = $vendor->scorecards()
            ->where('period_year', $year)
            ->where('period_quarter', $quarter)
            ->get();

        $mean = fn (string $column): string => $this->mean(
            $cards->map(fn (VendorScorecard $card): string => (string) $card->{$column})->all()
        );

        return [
            'cards' => $cards->count(),
            'price_score' => $mean('price_score'),
            'delivery_score' => $mean('delivery_score'),
            'quality_score' => $mean('quality_score'),
            'documents_score' => $mean('documents_score'),
            'overall_score' => $mean('overall_score'),
            'late_deliveries' => $cards->filter(fn (VendorScorecard $card): bool => $card->days_late > 0)->count(),
        ];
    }

    /**
     * Raise a warranty claim — F11's missing trigger.
     *
     * The certificate is optional and the claim is not. Goods whose warranty
     * nobody collected still fail, and refusing the claim for want of paperwork
     * would disarm the trigger for exactly the vendors whose documents are
     * worst. When a certificate IS on file, P5-03's register passes it and the
     * claim is tied to the promise it is made under.
     *
     * It suspends immediately rather than waiting for the quarterly review. The
     * point of a warranty claim is that the goods have already failed in
     * service, and a vendor should not be collecting new RFQs while the company
     * works out whose fault it was.
     */
    public function raiseWarrantyClaim(
        Vendor $vendor,
        ?PurchaseOrder $order,
        string $description,
        User $by,
        ?Warranty $warranty = null,
        ?CarbonInterface $failedOn = null,
    ): WarrantyClaim {
        if (trim($description) === '') {
            throw new DomainException('A warranty claim needs a description — it is what the vendor has to answer.');
        }

        if ($warranty !== null && (int) $warranty->vendor_id !== $vendor->getKey()) {
            // The composite foreign key refuses this too. It is checked here as
            // well because a database error is not something a clerk can act on.
            throw new DomainException(sprintf(
                'Warranty %s was issued by another vendor. A claim against the wrong certificate suspends the wrong company.',
                $warranty->number,
            ));
        }

        return DB::transaction(function () use ($vendor, $order, $description, $by, $warranty, $failedOn): WarrantyClaim {
            $claim = WarrantyClaim::query()->create([
                'vendor_id' => $vendor->getKey(),
                'purchase_order_id' => $order?->getKey(),
                'warranty_id' => $warranty?->getKey(),
                'number' => $this->numbering->next('WC'),
                'description' => $description,
                'failed_on' => $failedOn,
                'raised_at' => now(),
                'raised_by_user_id' => $by->getKey(),
            ]);

            if ($vendor->status !== VendorStatus::Removed) {
                $this->vendors->suspend(
                    $vendor,
                    VendorSuspensionReason::WarrantyClaim,
                    $by,
                    sprintf('Warranty claim %s raised.', $claim->number),
                );
            }

            return $claim;
        });
    }

    /**
     * Settle a claim. The record stays; only the trigger is lifted.
     */
    public function resolveWarrantyClaim(WarrantyClaim $claim, string $resolution, User $by): WarrantyClaim
    {
        if ($claim->resolved_at !== null) {
            throw new DomainException(sprintf('Warranty claim %s was already resolved.', $claim->number));
        }

        $claim->update([
            'resolved_at' => now(),
            'resolved_by_user_id' => $by->getKey(),
            'resolution' => $resolution,
        ]);

        return $claim->refresh();
    }

    /**
     * Does the vendor have a claim still open against it?
     */
    public function hasUnresolvedWarrantyClaim(Vendor $vendor): bool
    {
        return $vendor->warrantyClaims()->whereNull('resolved_at')->exists();
    }

    /**
     * How late the delivery was against the order's promised date.
     *
     * An order with no delivery date promised nothing, so nothing can be late
     * against it — scored as on time rather than as a failure the vendor had no
     * way to avoid.
     */
    private function daysLate(PurchaseOrder $order, CarbonInterface $receivedAt): int
    {
        if ($order->delivery_date === null) {
            return 0;
        }

        $promised = $order->delivery_date->copy()->endOfDay();

        return $receivedAt->greaterThan($promised)
            ? (int) $promised->diffInDays($receivedAt)
            : 0;
    }

    /**
     * Rejected quantity as a percentage of what arrived.
     */
    private function rejectionRate(ReceivingReport $report): string
    {
        $inspection = $report->inspection()->first();

        if ($inspection === null) {
            return '0.00';
        }

        $delivered = '0.0000';
        $rejected = '0.0000';

        foreach ($inspection->lines()->get() as $line) {
            $delivered = Money::sum($delivered, (string) $line->quantity_accepted, (string) $line->quantity_rejected);
            $rejected = Money::sum($rejected, (string) $line->quantity_rejected);
        }

        if (Money::isZero($delivered)) {
            return '0.00';
        }

        return Money::round(
            bcmul(bcdiv($rejected, $delivered, Money::WORKING_SCALE), '100', Money::WORKING_SCALE),
            2,
        );
    }

    /**
     * How the awarded price compared with the canvass behind it.
     *
     * The winner of its own tabulation scores full marks: it WAS the best price
     * available on the day, which is the only thing a canvass can establish. An
     * award above the recommended amount loses proportionally — that is a
     * decision somebody made, and the scorecard is where it shows.
     */
    private function priceScore(PurchaseOrder $order): string
    {
        $tabulation = $order->tabulation()->first();

        if ($tabulation === null) {
            return '100.00';
        }

        $recommended = (string) $tabulation->recommended_amount;

        if (Money::isZero($recommended) || ! Money::greaterThan((string) $order->total_amount, $recommended)) {
            return '100.00';
        }

        $excess = bcsub((string) $order->total_amount, $recommended, Money::SCALE);
        $penalty = Money::round(bcmul(bcdiv($excess, $recommended, Money::WORKING_SCALE), '100', Money::WORKING_SCALE), 2);

        return bccomp($penalty, '100.00', 2) >= 0 ? '0.00' : bcsub('100.00', $penalty, 2);
    }

    /**
     * F12's fourth dimension: is the paperwork actually on file?
     *
     * Four documents are expected of a completed order — the goods signed for,
     * a verdict recorded, the invoice reconciled, and a validation visit on the
     * vendor's accreditation file (F15's record). Each missing one is a quarter
     * of the score, because each is a real piece of work somebody else has to
     * chase.
     */
    private function documentsScore(PurchaseOrder $order, ReceivingReport $report): string
    {
        $expected = [
            trim((string) $report->delivery_receipt_number) !== '',
            $report->inspection()->exists(),
            $order->threeWayMatches()->exists(),
            $order->vendor()->sole()->validationVisits()->exists(),
        ];

        $present = count(array_filter($expected));

        return Money::round(
            bcmul(bcdiv((string) $present, (string) count($expected), Money::WORKING_SCALE), '100', Money::WORKING_SCALE),
            2,
        );
    }

    /**
     * The straight mean of a set of scores, in bcmath.
     *
     * @param  array<int, string>  $values
     */
    private function mean(array $values): string
    {
        if ($values === []) {
            return '0.00';
        }

        $total = '0.00';

        foreach ($values as $value) {
            $total = bcadd($total, $value, 2);
        }

        return Money::round(bcdiv($total, (string) count($values), Money::WORKING_SCALE), 2);
    }
}
