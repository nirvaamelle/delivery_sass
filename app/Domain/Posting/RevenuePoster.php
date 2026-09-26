<?php

namespace App\Domain\Posting;

use App\Domain\Cutoffs\CutoffType;
use App\Models\CostCode;
use App\Models\ProjectCostLedgerEntry;
use App\Models\SalesInvoice;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Revenue reaching `project_cost_ledger` — P2-09, and the second half of Phase
 * 2's exit gate.
 *
 * Two decisions, both about timing and amount.
 *
 * **Revenue is recognised at the INVOICE, not at the billing and not at the
 * collection.** A billing is a claim the client has not yet accepted — booking
 * it as revenue would recognise income the client is about to dispute, which is
 * exactly what the returned branch exists for. A collection is the cash arriving,
 * which is a different event: recognising revenue there would make the P&L a
 * cash statement and leave every unpaid invoice invisible to it.
 *
 * **And at the GROSS.** Retention is money earned and withheld, not money
 * unearned: the work was done, the client agreed it was done, and they are
 * holding 10% of the payment until the defects liability period ends. Booking
 * revenue net of retention would understate every project by 10% until close-out
 * and then produce a sudden unexplained gain.
 *
 * Like `MaterialCostPoster`, nothing is written here directly — `LedgerPoster`
 * is the only code permitted to write the ledger, so the cutoff check and the
 * append-only guarantee apply without being restated.
 */
class RevenuePoster
{
    public function __construct(
        private readonly LedgerPoster $ledger,
    ) {}

    /**
     * Post an invoice's revenue.
     *
     * @throws DomainException when the invoice is already posted, or the project
     *                         has no cost code to post against
     */
    public function post(SalesInvoice $invoice, ?CostCode $costCode = null, ?string $description = null): ProjectCostLedgerEntry
    {
        if ($invoice->project_cost_ledger_entry_id !== null) {
            throw new DomainException(sprintf(
                'Invoice %s is already in the ledger as entry %d. Posting it twice books the same revenue in two months.',
                $invoice->number,
                $invoice->project_cost_ledger_entry_id,
            ));
        }

        $project = $invoice->project()->sole();

        /*
         * Revenue needs a cost code like every other posting — PLAN.md §1 asks
         * every row to carry one. A project's revenue code is not a concept the
         * deck names, so the caller may pass one and otherwise the project's
         * first code is used.
         *
         * PLACEHOLDER: which cost code revenue posts against is a chart-of-
         * accounts question the client has not answered.
         */
        $costCode = $costCode ?? CostCode::query()
            ->where('organization_id', $project->organization_id)
            ->orderBy('code')
            ->first();

        if ($costCode === null) {
            throw new DomainException(sprintf(
                'Project %s has no cost code to post revenue against, and a ledger row without one cannot be traced.',
                $project->code,
            ));
        }

        return DB::transaction(function () use ($invoice, $project, $costCode, $description): ProjectCostLedgerEntry {
            $entry = $this->ledger->post(
                project: $project,
                costCode: $costCode,
                category: LedgerCategory::Revenue,
                // Gross: retention is earned and withheld, not unearned.
                amount: (string) $invoice->gross_amount,
                sourceDocument: $invoice,
                documentNumber: $invoice->number,
                // The billing calendar — this is the chain it closes on.
                cutoffType: CutoffType::Billing,
                documentDate: $invoice->issued_on,
                description: $description ?? sprintf('Revenue: %s', $invoice->billing()->sole()->number),
            );

            $invoice->update([
                'posted_at' => now(),
                'project_cost_ledger_entry_id' => $entry->getKey(),
            ]);

            return $entry;
        });
    }
}
