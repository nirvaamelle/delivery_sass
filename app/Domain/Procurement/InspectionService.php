<?php

namespace App\Domain\Procurement;

use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\Inspection;
use App\Models\ReceivingReport;
use App\Models\ReceivingReportLine;
use App\Models\ReturnToVendor;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inspection and return to vendor.
 *
 * Arriving is not the same as being acceptable. The gap between receiving and
 * issuance is where rejected material lives: it is on site, it has been signed
 * for, and it must reach neither stock nor a payment.
 *
 * **The rule that carries this is that accepted + rejected must equal what
 * arrived.** Not a tidiness check — 480 accepted and 10 rejected out of 500
 * leaves ten units that exist physically and in no record, which is precisely
 * how material walks off a site. The accepted figure is what P1-10 issues from
 * and what P1-11 will pay against, so a discrepancy here propagates into both.
 *
 * A rejection also needs a reason. The vendor has to be able to answer it, and
 * the scorecard in P1-14 counts rejections — an unrecorded reason is an
 * uncountable one.
 */
class InspectionService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly DocumentLinker $links,
    ) {}

    /**
     * Inspect a delivery.
     *
     * @param  array<int, array{receiving_report_line_id: int, quantity_accepted: string, quantity_rejected: string, rejection_reason?: string}>  $lines
     *
     * @throws DomainException when already inspected, or a line does not reconcile
     * @throws InvalidArgumentException when a rejection carries no reason
     */
    public function inspect(ReceivingReport $report, array $lines, ?User $inspectedBy = null, ?string $remarks = null): Inspection
    {
        if ($report->inspection()->exists()) {
            throw new DomainException(sprintf(
                'Receiving report %s has already been inspected. Two verdicts on one delivery leaves nothing to say which stock and payment followed.',
                $report->number,
            ));
        }

        $totalAccepted = '0';
        $totalRejected = '0';

        // Validated before anything is written, so a failure leaves no partial
        // inspection behind.
        foreach ($lines as $line) {
            $reportLine = ReceivingReportLine::query()->findOrFail($line['receiving_report_line_id']);

            if ((int) $reportLine->receiving_report_id !== (int) $report->getKey()) {
                throw new DomainException('That line belongs to a different receiving report.');
            }

            $accounted = Money::sum($line['quantity_accepted'], $line['quantity_rejected']);

            if (bccomp($accounted, (string) $reportLine->quantity_received, Money::SCALE) !== 0) {
                throw new DomainException(sprintf(
                    'Line %d: %s arrived but %s is accounted for. Material that is neither accepted nor rejected exists on site and in no record.',
                    $reportLine->getKey(),
                    $reportLine->quantity_received,
                    $accounted,
                ));
            }

            if (! Money::isZero($line['quantity_rejected']) && trim($line['rejection_reason'] ?? '') === '') {
                throw new InvalidArgumentException(sprintf(
                    'Line %d rejects %s with no reason. The vendor has to be able to answer it, and the scorecard counts it.',
                    $reportLine->getKey(),
                    $line['quantity_rejected'],
                ));
            }

            $totalAccepted = Money::sum($totalAccepted, $line['quantity_accepted']);
            $totalRejected = Money::sum($totalRejected, $line['quantity_rejected']);
        }

        $verdict = match (true) {
            Money::isZero($totalRejected) => InspectionVerdict::Passed,
            Money::isZero($totalAccepted) => InspectionVerdict::Rejected,
            default => InspectionVerdict::PartiallyRejected,
        };

        return DB::transaction(function () use ($report, $lines, $inspectedBy, $remarks, $verdict): Inspection {
            $inspection = Inspection::query()->create([
                'receiving_report_id' => $report->getKey(),
                'number' => $this->numbering->next('INS'),
                'verdict' => $verdict,
                'inspected_at' => now(),
                'inspected_by_user_id' => $inspectedBy?->getKey(),
                'remarks' => $remarks,
            ]);

            foreach ($lines as $line) {
                $inspection->lines()->create([
                    'receiving_report_line_id' => $line['receiving_report_line_id'],
                    'quantity_accepted' => $line['quantity_accepted'],
                    'quantity_rejected' => $line['quantity_rejected'],
                    'rejection_reason' => $line['rejection_reason'] ?? null,
                ]);
            }

            $this->links->link($report, $inspection);

            return $inspection->refresh();
        });
    }

    /**
     * Raise the document that sends rejected material back.
     *
     * @throws DomainException when nothing was rejected
     */
    public function returnToVendor(Inspection $inspection, ?string $remarks = null): ReturnToVendor
    {
        $rejected = $this->rejectedFor($inspection);

        if (Money::isZero($rejected)) {
            throw new DomainException(sprintf(
                'Inspection %s rejected nothing, so there is nothing to return.',
                $inspection->number,
            ));
        }

        $vendorId = $inspection->receivingReport()->sole()
            ->purchaseOrder()->sole()
            ->vendor_id;

        return DB::transaction(function () use ($inspection, $rejected, $vendorId, $remarks): ReturnToVendor {
            $rtv = ReturnToVendor::query()->create([
                'inspection_id' => $inspection->getKey(),
                'vendor_id' => $vendorId,
                'number' => $this->numbering->next('RTV'),
                'quantity_returned' => $rejected,
                'returned_at' => now(),
                'remarks' => $remarks,
            ]);

            $this->links->link($inspection, $rtv);

            return $rtv;
        });
    }

    /**
     * What passed inspection — the quantity that may be issued and paid.
     */
    public function acceptedFor(ReceivingReport $report): string
    {
        $inspection = $report->inspection()->first();

        if ($inspection === null) {
            // Uninspected material has not been accepted. Treating it as
            // accepted would let goods bypass the check entirely by simply
            // never being inspected.
            return '0.0000';
        }

        $accepted = '0';

        foreach ($inspection->lines()->get() as $line) {
            $accepted = Money::sum($accepted, (string) $line->quantity_accepted);
        }

        return $accepted;
    }

    public function rejectedFor(Inspection $inspection): string
    {
        $rejected = '0';

        foreach ($inspection->lines()->get() as $line) {
            $rejected = Money::sum($rejected, (string) $line->quantity_rejected);
        }

        return $rejected;
    }
}
