<?php

namespace App\Domain\Billing;

use App\Domain\Documents\DocumentLinker;
use App\Domain\Gates\Gatekeeper;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Domain\Tax\WithholdingCalculator;
use App\Models\Billing;
use App\Models\OfficialReceipt;
use App\Models\SalesInvoice;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Sales invoices and collection — slide 6's approved branch, continued.
 *
 * **F9's other half lands here.** P0-09 built the withholding shape for supplier
 * payments, where the company withholds from the vendor. The direction now
 * reverses: the client withholds from us, and what arrives in the bank is less
 * than the invoice. That gap is not a shortfall to chase — it is creditable
 * withholding, and the certificate proving it is worth exactly the amount
 * withheld. A collection recorded without the certificate reference is tax the
 * company has paid and cannot claim back, and it is invisible, because the
 * invoice reads as settled.
 *
 * **Retention is not collectible.** The client holds it until the defects
 * liability period ends, so invoicing it now would put a receivable nobody
 * intends to pay yet in front of the weekly AR sweep — which trains everybody to
 * ignore the sweep. It sits on the invoice as a figure and comes out of what is
 * chased.
 *
 * The gate is slide 3's step 7 read carefully: an invoice follows an APPROVED
 * billing. A returned billing is one the client disputed; invoicing it bills them
 * for work they have just said was not done.
 */
class CollectionService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly DocumentLinker $links,
        private readonly WithholdingCalculator $withholding,
        private readonly Gatekeeper $gates,
    ) {}

    /**
     * Raise the invoice for an approved billing.
     *
     * @throws DomainException when the billing has not been approved
     */
    public function invoice(
        Billing $billing,
        CarbonInterface $issuedOn,
        ?string $withholdingCode = null,
        ?User $by = null,
    ): SalesInvoice {
        if ($billing->status !== BillingStatus::Approved) {
            throw new DomainException(sprintf(
                'Billing %s is %s, not approved. An invoice follows the client accepting the billing — a returned one bills them for work they have just disputed.',
                $billing->number,
                $billing->status->value,
            ));
        }

        // Slide 9 step 4, enforced at the door rather than remembered: a
        // final billing cannot be invoiced over a back-charge that is not on
        // it. Declared in GateServiceProvider with every other control.
        $this->gates->assert($billing, BillingTransition::Invoice->value);

        $gross = (string) $billing->gross_amount;
        $retention = (string) $billing->retention_amount;
        $deductions = (string) $billing->deductions_amount;

        $rate = $withholdingCode === null ? null : $this->withholding->rateFor($withholdingCode);
        $withheld = $withholdingCode === null ? '0.0000' : $this->withholding->compute($gross, $withholdingCode);

        /*
         * Gross less retention less deductions less withholding: what should
         * actually arrive. The deductions are read off the billing rather than
         * recomputed here — they were applied BEFORE this moment, which is the
         * whole of slide 9's step 4, and a figure recomputed at invoice time
         * would silently restate an invoice that has already been sent.
         */
        $collectible = bcsub(
            bcsub(bcsub($gross, $retention, Money::SCALE), $deductions, Money::SCALE),
            $withheld,
            Money::SCALE,
        );

        return DB::transaction(function () use ($billing, $issuedOn, $gross, $retention, $withholdingCode, $rate, $withheld, $collectible, $by): SalesInvoice {
            $invoice = SalesInvoice::query()->create([
                'billing_id' => $billing->getKey(),
                'project_id' => $billing->project_id,
                'number' => $this->numbering->next('SI'),
                'status' => InvoiceStatus::Issued,
                'issued_on' => $issuedOn,
                'gross_amount' => $gross,
                'retention_amount' => $retention,
                'collectible_amount' => $collectible,
                'withholding_code' => $withholdingCode,
                'withholding_rate' => $rate,
                'withholding_amount' => $withheld,
                'issued_by_user_id' => $by?->getKey(),
            ]);

            $this->links->link($billing, $invoice);

            return $invoice->refresh();
        });
    }

    /**
     * Record money received.
     *
     * @throws DomainException when the amount is not positive, exceeds what is
     *                         outstanding, or arrives with tax withheld and no
     *                         certificate to substantiate it
     */
    public function collect(
        SalesInvoice $invoice,
        string $amount,
        CarbonInterface $receivedOn,
        ?string $paymentReference = null,
        ?string $certificateReference = null,
        ?User $by = null,
        ?string $remarks = null,
    ): OfficialReceipt {
        if (bccomp($amount, '0.0000', Money::SCALE) <= 0) {
            throw new DomainException('A receipt for nothing is not a collection.');
        }

        $outstanding = $this->outstandingFor($invoice);

        if (Money::greaterThan($amount, $outstanding)) {
            // Over-collection is almost never generosity. It is a misapplied
            // payment, usually one belonging to another invoice, and it hides
            // there until a reconciliation finds it.
            throw new DomainException(sprintf(
                'Invoice %s has %s outstanding; this receipt is for %s. An over-collection is a payment applied to the wrong invoice.',
                $invoice->number,
                $outstanding,
                $amount,
            ));
        }

        if (! Money::isZero((string) $invoice->withholding_amount) && trim((string) $certificateReference) === '') {
            throw new DomainException(sprintf(
                'Invoice %s had %s withheld at source. Without the certificate reference that amount is tax the company has paid and cannot claim back — and the invoice still reads as settled.',
                $invoice->number,
                $invoice->withholding_amount,
            ));
        }

        return DB::transaction(function () use ($invoice, $amount, $receivedOn, $paymentReference, $certificateReference, $by, $remarks): OfficialReceipt {
            $receipt = OfficialReceipt::query()->create([
                'sales_invoice_id' => $invoice->getKey(),
                'number' => $this->numbering->next('OR'),
                'amount_received' => $amount,
                'received_on' => $receivedOn,
                'payment_reference' => $paymentReference,
                'withholding_certificate_reference' => $certificateReference,
                'received_by_user_id' => $by?->getKey(),
                'remarks' => $remarks,
            ]);

            $invoice->update([
                'status' => Money::isZero($this->outstandingFor($invoice->fresh()))
                    ? InvoiceStatus::Collected
                    : InvoiceStatus::PartlyCollected,
            ]);

            $this->links->link($invoice, $receipt);

            return $receipt->refresh();
        });
    }

    /**
     * What is still to come in on this invoice.
     *
     * Summed from the receipts in bcmath rather than tracked in a balance
     * column, for the same reason the stock card has no stored balance: a figure
     * anything can write is a figure nobody can explain, and this one is what
     * the AR aging sweep chases.
     */
    public function outstandingFor(SalesInvoice $invoice): string
    {
        $collected = '0.0000';

        foreach ($invoice->receipts()->pluck('amount_received') as $amount) {
            $collected = Money::sum($collected, (string) $amount);
        }

        return bcsub((string) $invoice->collectible_amount, $collected, Money::SCALE);
    }

    /**
     * What has been received against this invoice.
     */
    public function collectedFor(SalesInvoice $invoice): string
    {
        return bcsub((string) $invoice->collectible_amount, $this->outstandingFor($invoice), Money::SCALE);
    }
}
