<?php

namespace App\Domain\Procurement;

use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Requisitions\RequisitionStatus;
use App\Domain\Vendors\VendorService;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\RfqRecipient;
use App\Models\Vendor;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Requests for quotation — PLAN.md §5.
 *
 *   "Accreditation expires at 12 months; expired vendors cannot receive an RFQ."
 *   "Three quotes minimum before award."
 *
 * Both controls live here, and where each is checked matters:
 *
 * **Eligibility is checked when a recipient is ADDED**, not when the RFQ is
 * issued. Checking only at issue time lets an expired vendor sit on the draft
 * looking invited, and whoever assembled the list discovers the problem at the
 * last possible moment. Refusing at the point the mistake is made is the whole
 * value of having the rule in software rather than on a checklist.
 *
 * **The three-quote minimum is checked at ISSUE**, not at award. Sending to two
 * and hoping a third turns up is how a canvass ends up needing a sole-source
 * justification written after the fact, to explain a decision that was already
 * made.
 */
class RfqService
{
    /**
     * PLAN.md §5: three quotes minimum before award.
     */
    private const MINIMUM_RECIPIENTS = 3;

    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly VendorService $vendors,
        private readonly DocumentLinker $links,
    ) {}

    /**
     * Open a draft RFQ against an approved requisition.
     *
     * @throws DomainException when the requisition is not approved
     */
    public function open(
        PurchaseRequisition $requisition,
        CarbonInterface $quotationDeadline,
        bool $soleSource = false,
    ): Rfq {
        if ($requisition->status !== RequisitionStatus::Approved) {
            throw new DomainException(sprintf(
                'Requisition %s is %s. An RFQ needs an approved requisition behind it — otherwise vendors are being asked to quote on a purchase nobody authorised.',
                $requisition->number,
                $requisition->status->value,
            ));
        }

        return DB::transaction(function () use ($requisition, $quotationDeadline, $soleSource): Rfq {
            $rfq = Rfq::mutate(fn (): Rfq => Rfq::query()->create([
                'purchase_requisition_id' => $requisition->getKey(),
                'number' => $this->numbering->next('RFQ'),
                'status' => RfqStatus::Draft,
                'quotation_deadline' => $quotationDeadline,
                'sole_source' => $soleSource,
            ]));

            // The handoff spine, PLAN.md §1. This is the first real pair of
            // documents on it: every later document in the chain will trace
            // back through here to the requisition that started it.
            $this->links->link($requisition, $rfq);

            return $rfq;
        });
    }

    /**
     * Invite a vendor to quote.
     *
     * @throws VendorNotEligibleException when the vendor may not be sent an RFQ
     */
    public function invite(Rfq $rfq, Vendor $vendor): RfqRecipient
    {
        // The recipient list is what the three-quote minimum is measured
        // against, so it closes when the RFQ is issued. Adding afterwards would
        // make that check describe a list that no longer exists.
        $this->assertDraft($rfq, 'invite a vendor to');

        if (! $this->vendors->isAccredited($vendor)) {
            throw new VendorNotEligibleException(sprintf(
                'Vendor %s (%s) cannot receive an RFQ: accreditation is not current.',
                $vendor->code,
                $vendor->status->value,
            ));
        }

        return $rfq->recipients()->create([
            'vendor_id' => $vendor->getKey(),
        ]);
    }

    /**
     * Issue the RFQ to everyone invited.
     *
     * @throws DomainException when fewer than three vendors are invited and the
     *                         RFQ is not a declared sole source
     */
    public function issue(Rfq $rfq): Rfq
    {
        // Issuing twice re-sends a closed solicitation, and issuing a cancelled
        // one revives a document somebody deliberately withdrew.
        $this->assertDraft($rfq, 'issue');

        $count = $rfq->recipients()->count();

        if ($count === 0) {
            throw new DomainException('An RFQ cannot be issued to nobody.');
        }

        // Sole source is the documented exception, and it is a decision rather
        // than an accident: it is declared when the RFQ is opened, and P1-05
        // requires the written justification approved one level above.
        if (! $rfq->sole_source && $count < self::MINIMUM_RECIPIENTS) {
            throw new DomainException(sprintf(
                'RFQ %s has %d recipient(s); PLAN.md §5 requires %d. Declare it sole source with a written justification, or invite more vendors.',
                $rfq->number,
                $count,
                self::MINIMUM_RECIPIENTS,
            ));
        }

        return DB::transaction(fn (): Rfq => Rfq::mutate(function () use ($rfq): Rfq {
            $rfq->update([
                'status' => RfqStatus::Issued,
                'issued_at' => now(),
            ]);

            $rfq->recipients()->update(['sent_at' => now()]);

            return $rfq->refresh();
        }));
    }

    /**
     * Withdraw an RFQ that will not be issued.
     *
     * A cancelled RFQ is terminal: `assertDraft` refuses to issue it, so the
     * document cannot be revived after somebody deliberately withdrew it.
     */
    public function cancel(Rfq $rfq, string $reason): Rfq
    {
        $this->assertDraft($rfq, 'cancel');

        return DB::transaction(fn (): Rfq => Rfq::mutate(function () use ($rfq, $reason): Rfq {
            $rfq->update([
                'status' => RfqStatus::Cancelled,
                'cancellation_reason' => $reason,
            ]);

            return $rfq->refresh();
        }));
    }

    /**
     * @throws DomainException when the RFQ has left draft
     */
    private function assertDraft(Rfq $rfq, string $action): void
    {
        if ($rfq->status !== RfqStatus::Draft) {
            throw new DomainException(sprintf(
                'Cannot %s RFQ %s: it is %s, not draft.',
                $action,
                $rfq->number,
                $rfq->status->value,
            ));
        }
    }
}
