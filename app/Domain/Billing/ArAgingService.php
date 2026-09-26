<?php

namespace App\Domain\Billing;

use App\Domain\Support\Money;
use App\Models\ArEscalation;
use App\Models\Project;
use App\Models\SalesInvoice;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AR aging and the thirty-day escalation — F13.
 *
 * The finding draws a distinction that is the whole task: **a report is
 * something somebody has to open; an escalation arrives.** Slide 6 asks for
 * both — "AR aging reviewed weekly; unpaid billings escalated to the project
 * manager at 30 days" — and the second is the one that never gets built,
 * because the first looks like it covers it.
 *
 * Three rules make the escalation real rather than decorative:
 *
 *   - it has a **named recipient**, the project manager, because "escalated"
 *     with nobody to escalate to is a status change;
 *   - it fires **at thirty days**, on the boundary, not whenever the weekly
 *     sweep happens to run;
 *   - it does **not repeat**. The sweep runs weekly; an escalation that reappears
 *     every week until the client pays teaches its reader to ignore the inbox,
 *     which leaves the company worse off than not having built it. It escalates
 *     again only when the invoice crosses into a further bucket, because sixty
 *     days is a different conversation from thirty.
 *
 * Only what is COLLECTIBLE ages. Retention is held by agreement until the
 * defects liability period ends, so including it would chase money nobody is
 * late paying — and a sweep that cries wolf is a sweep nobody reads.
 *
 * PLACEHOLDER: Part D item 17 — which role owns AR follow-up is unconfirmed. The
 * deck says "project manager", so that is the role addressed.
 */
class ArAgingService
{
    /**
     * Slide 6's threshold.
     */
    private const ESCALATION_DAYS = 30;

    /**
     * The deck's named recipient.
     */
    private const ESCALATION_ROLE = 'project-manager';

    public function __construct(
        private readonly CollectionService $collections,
    ) {}

    /**
     * Run the sweep, raising escalations for anything thirty days overdue.
     *
     * Returns only what it raised THIS run, so a caller can act on new
     * escalations rather than re-reading the whole backlog.
     *
     * @return Collection<int, ArEscalation>
     */
    public function sweep(?CarbonInterface $asOf = null, ?Project $project = null): Collection
    {
        $asOf = $asOf ?? now();
        $raised = collect();

        foreach ($this->openInvoices($project) as $invoice) {
            $days = $this->daysOutstanding($invoice, $asOf);

            if ($days < self::ESCALATION_DAYS) {
                continue;
            }

            $outstanding = $this->outstandingFor($invoice);

            if (Money::isZero($outstanding)) {
                continue;
            }

            $bucket = AgingBucket::forDays($days);

            $already = ArEscalation::query()
                ->where('sales_invoice_id', $invoice->getKey())
                ->where('bucket', $bucket)
                ->exists();

            if ($already) {
                continue;
            }

            $raised->push(DB::transaction(fn (): ArEscalation => ArEscalation::query()->create([
                'sales_invoice_id' => $invoice->getKey(),
                'project_id' => $invoice->project_id,
                'addressed_to_role' => self::ESCALATION_ROLE,
                'days_outstanding' => $days,
                'amount_outstanding' => $outstanding,
                'bucket' => $bucket,
                'escalated_at' => $asOf,
            ])));
        }

        return $raised;
    }

    /**
     * Close an escalation.
     *
     * @throws DomainException when it is already acknowledged
     */
    public function acknowledge(ArEscalation $escalation, User $by, string $resolution): ArEscalation
    {
        if ($escalation->acknowledged_at !== null) {
            throw new DomainException('That escalation was already acknowledged.');
        }

        if (trim($resolution) === '') {
            throw new DomainException(
                'Acknowledging an escalation needs a resolution — what was done about it is the only part worth reading later.'
            );
        }

        $escalation->update([
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $by->getKey(),
            'resolution' => $resolution,
        ]);

        return $escalation->refresh();
    }

    /**
     * Which bucket an invoice sits in today.
     */
    public function bucketFor(SalesInvoice $invoice, ?CarbonInterface $asOf = null): AgingBucket
    {
        return AgingBucket::forDays($this->daysOutstanding($invoice, $asOf ?? now()));
    }

    /**
     * What is still collectible on this invoice.
     *
     * Deliberately delegated to the collection service rather than recomputed:
     * two places computing the same balance is two places that can disagree
     * about whether a client owes money.
     */
    public function outstandingFor(SalesInvoice $invoice): string
    {
        return $this->collections->outstandingFor($invoice);
    }

    /**
     * The weekly review — one number per bucket.
     *
     * @return array<string, string>
     */
    public function summaryFor(?Project $project = null, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?? now();

        $summary = [];

        foreach (AgingBucket::cases() as $bucket) {
            $summary[$bucket->value] = '0.0000';
        }

        foreach ($this->openInvoices($project) as $invoice) {
            $outstanding = $this->outstandingFor($invoice);

            if (Money::isZero($outstanding)) {
                continue;
            }

            $bucket = $this->bucketFor($invoice, $asOf);
            $summary[$bucket->value] = Money::sum($summary[$bucket->value], $outstanding);
        }

        return $summary;
    }

    /**
     * How long this invoice has been unpaid.
     */
    public function daysOutstanding(SalesInvoice $invoice, ?CarbonInterface $asOf = null): int
    {
        $asOf = ($asOf ?? now())->startOfDay();

        return $asOf->lte($invoice->issued_on)
            ? 0
            : (int) $invoice->issued_on->copy()->startOfDay()->diffInDays($asOf);
    }

    /**
     * Invoices with money still to come in.
     *
     * @return EloquentCollection<int, SalesInvoice>
     */
    private function openInvoices(?Project $project = null): EloquentCollection
    {
        return SalesInvoice::query()
            ->when($project !== null, fn ($query) => $query->where('project_id', $project->getKey()))
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartlyCollected])
            ->orderBy('issued_on')
            ->get();
    }
}
