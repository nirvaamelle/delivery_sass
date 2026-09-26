<?php

namespace App\Domain\Procurement;

use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Approvals\ApproverLacksAuthorityException;
use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Domain\Tax\WithholdingCalculator;
use App\Models\Approval;
use App\Models\ApVoucher;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\ThreeWayMatch;
use App\Models\User;
use App\Models\VendorAdvance;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * AP vouchers and vendor advances — the two ways money leaves against a
 * purchase, and the reason they are in one service.
 *
 * A voucher is raised FROM a match: the signature takes `ThreeWayMatch`, not an
 * amount, so there is no arrangement of arguments that produces a payment with
 * nothing behind it. The amounts are computed here rather than passed in for the
 * same reason — a caller that could supply the net could supply any net.
 *
 * **An advance is the exception the control has to accommodate — F8.** It is a
 * real payment with no receipt behind it, because the goods have not shipped and
 * the supplier needs funding to make them. The wrong answer is to force it
 * through the match, which means inventing a delivery; the right one is to
 * recognise it as a distinct payment type, tie it to the order, and recover it
 * at the voucher. Without that recovery the vendor is paid twice for the same
 * goods, and both payments have a complete document set behind them.
 *
 * Order of arithmetic, which is a decision and not an accident: withholding is
 * computed on the GROSS, then the advance comes off. Withholding on the net of
 * an advance would under-withhold, and the shortfall surfaces months later when
 * the return is filed.
 */
class PayablesService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly DocumentLinker $links,
        private readonly WithholdingCalculator $withholding,
        private readonly ApprovalRouter $approvals,
    ) {}

    /**
     * Raise the payable for a completed match.
     *
     * No check is made here for an existing voucher. The unique key on
     * `three_way_match_id` is the guard, and it is the right one: a read-then-
     * write check races against a second request that has already read.
     *
     * @throws QueryException when the match is already paid
     */
    public function raise(ThreeWayMatch $match, string $withholdingCode, ?User $raisedBy = null): ApVoucher
    {
        $order = $match->purchaseOrder()->sole();

        $gross = (string) $match->invoice_amount;
        $rate = $this->withholding->rateFor($withholdingCode);
        $withheld = $this->withholding->compute($gross, $withholdingCode);

        // What is left to pay after tax is the most that can be recovered from
        // an advance. Recovering more would make the payable negative, which is
        // a receivable and not something a voucher can express.
        $payableAfterTax = Money::sum($gross, '-'.$withheld);
        $offset = $this->offsetAvailable($order, $payableAfterTax);

        $net = Money::sum($payableAfterTax, '-'.$offset);

        return DB::transaction(function () use ($match, $order, $gross, $rate, $withheld, $withholdingCode, $offset, $net, $raisedBy): ApVoucher {
            $voucher = ApVoucher::query()->create([
                'three_way_match_id' => $match->getKey(),
                'vendor_id' => $order->vendor_id,
                'project_id' => $order->project_id,
                'number' => $this->numbering->next('APV'),
                'gross_amount' => $gross,
                'withholding_code' => $withholdingCode,
                'withholding_rate' => $rate,
                'withholding_amount' => $withheld,
                'advance_offset' => $offset,
                'net_amount' => $net,
                'status' => ApVoucherStatus::Raised,
                'raised_at' => now(),
                'raised_by_user_id' => $raisedBy?->getKey(),
            ]);

            $this->applyOffset($order, $voucher, $offset);

            $this->links->link($match, $voucher);

            return $voucher->refresh();
        });
    }

    /**
     * Send a raised voucher to the approvers.
     *
     * **Routed by the NET amount**, which is what the company actually pays: the
     * withholding never leaves, and the advance left earlier. Routing on gross
     * would send payments up a tier they do not belong in, and an authority
     * matrix that routes on a number nobody pays is not an authority matrix.
     *
     * @throws DomainException when the voucher is not raised
     */
    public function submit(ApVoucher $voucher, ?User $by = null): ApVoucher
    {
        if ($voucher->status !== ApVoucherStatus::Raised) {
            throw new DomainException(sprintf(
                'AP voucher %s is %s. Only a raised voucher can be submitted for approval.',
                $voucher->number,
                $voucher->status->value,
            ));
        }

        return DB::transaction(function () use ($voucher, $by): ApVoucher {
            ApVoucher::mutate(fn () => $voucher->update([
                'status' => ApVoucherStatus::Submitted,
                'submitted_at' => now(),
            ]));

            $this->approvals->request($voucher, 'ap_voucher', (string) $voucher->net_amount);

            activity()->performedOn($voucher)->causedBy($by)->log('ap-voucher-submitted');

            return $voucher->refresh();
        });
    }

    /**
     * Record one approver's signature.
     *
     * Approved only when EVERY step of the tier has signed — a two-signature
     * tier acting on one signature is a one-signature tier.
     *
     * @throws ApproverLacksAuthorityException
     */
    public function approve(ApVoucher $voucher, Approval $step, User $approver, ?string $remarks = null): ApVoucher
    {
        return DB::transaction(function () use ($voucher, $step, $approver, $remarks): ApVoucher {
            $this->approvals->approve($step, $approver, $remarks);

            $outstanding = $voucher->approvals()
                ->where('decision', ApprovalDecision::Pending)
                ->exists();

            if (! $outstanding) {
                ApVoucher::mutate(fn () => $voucher->update(['status' => ApVoucherStatus::Approved]));
            }

            return $voucher->refresh();
        });
    }

    /**
     * Send the voucher back to the clerk who raised it.
     *
     * @throws ApproverLacksAuthorityException
     */
    public function returnForRevision(ApVoucher $voucher, Approval $step, User $approver, string $reason): ApVoucher
    {
        return DB::transaction(function () use ($voucher, $step, $approver, $reason): ApVoucher {
            $this->approvals->returnForRevision($step, $approver, $reason);

            ApVoucher::mutate(fn () => $voucher->update([
                'status' => ApVoucherStatus::Raised,
                'submitted_at' => null,
            ]));

            return $voucher->refresh();
        });
    }

    /**
     * Record the payment.
     *
     * **The reference is required** because it is the only thing tying this
     * voucher to a line on a bank statement. A payment nobody can reconcile is
     * how a double payment survives a review.
     *
     * **Paid is final.** A payment already made is corrected by a credit note
     * from the vendor, never by editing the voucher that recorded it.
     *
     * @throws DomainException when the voucher is not approved, is already paid,
     *                         or the reference is blank
     */
    public function pay(ApVoucher $voucher, string $reference, CarbonInterface $paidOn, ?User $by = null): ApVoucher
    {
        if ($voucher->status === ApVoucherStatus::Paid) {
            throw new DomainException(sprintf(
                'AP voucher %s was already paid on %s against %s. A second payment against one invoice is the defect this refusal exists for.',
                $voucher->number,
                $voucher->paid_at?->toDateString() ?? 'an unrecorded date',
                $voucher->payment_reference ?? '(no reference)',
            ));
        }

        if ($voucher->status !== ApVoucherStatus::Approved) {
            throw new DomainException(sprintf(
                'AP voucher %s is %s. Money leaves against an approved voucher, never an unapproved one.',
                $voucher->number,
                $voucher->status->value,
            ));
        }

        if (trim($reference) === '') {
            throw new DomainException('A payment needs its bank reference — it is what reconciles this voucher against the statement.');
        }

        return DB::transaction(function () use ($voucher, $reference, $paidOn, $by): ApVoucher {
            ApVoucher::mutate(fn () => $voucher->update([
                'status' => ApVoucherStatus::Paid,
                'payment_reference' => trim($reference),
                'paid_at' => $paidOn->toDateString(),
                'paid_by_user_id' => $by?->getKey(),
            ]));

            activity()->performedOn($voucher)->causedBy($by)
                ->withProperties(['reference' => trim($reference), 'paid_at' => $paidOn->toDateString()])
                ->log('ap-voucher-paid');

            return $voucher->refresh();
        });
    }

    /**
     * Cancel a voucher that will never be paid.
     *
     * **The advance it recovered goes back.** A voucher raised against an order
     * with an outstanding advance offsets that advance on the spot. Cancelling
     * without releasing the offset leaves the vendor's advance marked recovered
     * against a payment nobody will make: invisible to the next voucher, and
     * never collected.
     *
     * @throws DomainException when the voucher is paid, already cancelled, or
     *                         the reason is blank
     */
    public function cancel(ApVoucher $voucher, string $reason, ?User $by = null): ApVoucher
    {
        if ($voucher->status === ApVoucherStatus::Paid) {
            throw new DomainException(sprintf(
                'AP voucher %s is paid. Money that has left is corrected by a credit note from the vendor, not by cancelling the record of it.',
                $voucher->number,
            ));
        }

        if ($voucher->status === ApVoucherStatus::Cancelled) {
            throw new DomainException(sprintf('AP voucher %s is already cancelled.', $voucher->number));
        }

        if (trim($reason) === '') {
            throw new DomainException('Cancelling a voucher needs a reason.');
        }

        return DB::transaction(function () use ($voucher, $reason, $by): ApVoucher {
            $this->releaseOffset($voucher);

            ApVoucher::mutate(fn () => $voucher->update([
                'status' => ApVoucherStatus::Cancelled,
                'advance_offset' => '0.0000',
                'cancelled_at' => now(),
                'cancellation_reason' => trim($reason),
                'cancelled_by_user_id' => $by?->getKey(),
            ]));

            activity()->performedOn($voucher)->causedBy($by)
                ->withProperties(['reason' => trim($reason)])
                ->log('ap-voucher-cancelled');

            return $voucher->refresh();
        });
    }

    /**
     * What a project owes: approved, not yet paid.
     *
     * Summed in bcmath. A raised voucher is not owed yet — nobody has agreed to
     * it — and a cancelled one never was.
     */
    public function approvedUnpaidFor(Project $project): string
    {
        $total = '0.0000';

        $amounts = ApVoucher::query()
            ->where('project_id', $project->getKey())
            ->where('status', ApVoucherStatus::Approved)
            ->pluck('net_amount');

        foreach ($amounts as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    /**
     * Everything still out on advance, across every order.
     *
     * The register's headline figure. Summed in bcmath, like every other total
     * in the build.
     */
    public function outstandingAdvanceTotal(): string
    {
        $total = '0.0000';

        foreach (VendorAdvance::query()->get(['amount', 'offset_amount']) as $advance) {
            $total = Money::sum($total, bcsub((string) $advance->amount, (string) $advance->offset_amount, Money::SCALE));
        }

        return $total;
    }

    /**
     * Hand back the advance a cancelled voucher had recovered.
     */
    private function releaseOffset(ApVoucher $voucher): void
    {
        foreach ($voucher->advancesRecovered()->get() as $advance) {
            $advance->update([
                'offset_amount' => '0.0000',
                'offset_ap_voucher_id' => null,
            ]);
        }
    }

    /**
     * Release an advance against a purchase order — F8.
     *
     * @throws DomainException when the purpose is blank, or when the advance,
     *                         with those already released, exceeds the order
     */
    public function releaseAdvance(
        PurchaseOrder $order,
        string $amount,
        string $purpose,
        ?User $releasedBy = null,
    ): VendorAdvance {
        if (trim($purpose) === '') {
            throw new DomainException(
                'An advance needs a purpose. Money leaving before anything arrives is the payment an auditor opens first, and it has to be able to answer why.'
            );
        }

        $alreadyAdvanced = $this->advancedAgainst($order);
        $cumulative = Money::sum($alreadyAdvanced, $amount);
        $ordered = (string) $order->total_amount;

        // Cumulative, not per-advance: three advances of 40% each fit the order
        // one at a time and pay 20% more than the award between them.
        if (Money::greaterThan($cumulative, $ordered)) {
            throw new DomainException(sprintf(
                'Purchase order %s awarded %s and has already advanced %s. A further %s would pay the vendor more than the order is worth, against goods that have not arrived.',
                $order->number,
                $ordered,
                $alreadyAdvanced,
                $amount,
            ));
        }

        return DB::transaction(function () use ($order, $amount, $purpose, $releasedBy): VendorAdvance {
            $advance = VendorAdvance::query()->create([
                'purchase_order_id' => $order->getKey(),
                'vendor_id' => $order->vendor_id,
                'number' => $this->numbering->next('ADV'),
                'amount' => $amount,
                'purpose' => $purpose,
                'released_at' => now(),
                'released_by_user_id' => $releasedBy?->getKey(),
            ]);

            $this->links->link($order, $advance);

            return $advance->refresh();
        });
    }

    /**
     * How much has been advanced against an order.
     */
    public function advancedAgainst(PurchaseOrder $order): string
    {
        $total = '0.0000';

        foreach ($order->advances()->pluck('amount') as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    /**
     * How much of the advances against an order is still to be recovered.
     */
    public function outstandingAdvanceFor(PurchaseOrder $order): string
    {
        $outstanding = '0.0000';

        foreach ($order->advances()->get() as $advance) {
            $outstanding = Money::sum(
                $outstanding,
                bcsub((string) $advance->amount, (string) $advance->offset_amount, Money::SCALE),
            );
        }

        return $outstanding;
    }

    /**
     * The recoverable amount, capped at what the voucher can carry.
     */
    private function offsetAvailable(PurchaseOrder $order, string $payableAfterTax): string
    {
        $outstanding = $this->outstandingAdvanceFor($order);

        return Money::greaterThan($outstanding, $payableAfterTax)
            ? $payableAfterTax
            : $outstanding;
    }

    /**
     * Write the recovery back onto the advances, oldest first.
     *
     * Oldest first because an advance is recovered in the order it was released;
     * anything else leaves the earliest one open longest, which is the one most
     * likely to be forgotten when the order closes.
     */
    private function applyOffset(PurchaseOrder $order, ApVoucher $voucher, string $offset): void
    {
        $remaining = $offset;

        if (Money::isZero($remaining)) {
            return;
        }

        foreach ($order->advances()->orderBy('released_at')->orderBy('id')->get() as $advance) {
            if (Money::isZero($remaining)) {
                break;
            }

            $open = bcsub((string) $advance->amount, (string) $advance->offset_amount, Money::SCALE);

            if (Money::isZero($open)) {
                continue;
            }

            $applied = Money::greaterThan($open, $remaining) ? $remaining : $open;

            $advance->update([
                'offset_amount' => Money::sum((string) $advance->offset_amount, $applied),
                'offset_ap_voucher_id' => $voucher->getKey(),
            ]);

            $remaining = Money::sum($remaining, '-'.$applied);
        }
    }
}
