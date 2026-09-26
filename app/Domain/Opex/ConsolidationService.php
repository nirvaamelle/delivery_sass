<?php

namespace App\Domain\Opex;

use App\Domain\Billing\CollectionService;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\RetentionService;
use App\Domain\Hris\PayrollRunStatus;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Procurement\ApVoucherStatus;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Support\Money;
use App\Models\ApVoucher;
use App\Models\Organization;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use Illuminate\Support\Carbon;

/**
 * Consolidation, the project P&L, and F16's cash requirement — P4-08 and P4-09.
 *
 * **The P&L is assembled by summing, not by classifying.** P0-13 categorised
 * every posting when it was written, precisely so this report can add up rather
 * than decide what things are. A consolidation that re-classified would be a
 * second opinion about numbers already reported, and the two would eventually
 * disagree.
 *
 * **It reads the ledger, never the source documents.** PLAN.md §1 calls the
 * ledger the organising principle; a report built from expenses and payroll lines
 * directly would be a second path to the same figures.
 *
 * **F16 is the cash requirement, and it is not a P&L figure.** PHASE-PLAN.md:
 * "PLAN.md §4's ledger section produces a P&L, cost per unit and forecast to
 * completion, but no cash requirement." The two answer different questions. A
 * P&L recognises cost when it is incurred; cash leaves when somebody signs a
 * cheque, and an approved purchase order is money promised that appears in no
 * ledger category at all until the goods arrive. That gap is the whole finding.
 */
class ConsolidationService
{
    public function __construct(
        private readonly CollectionService $collections,
        private readonly RetentionService $retention,
    ) {}

    /**
     * The project P&L for a period, summed from the ledger.
     *
     * @return array{revenue: string, material: string, subcontract: string, labor: string, overhead: string, total_cost: string, gross_profit: string, margin_percent: string}
     */
    public function profitAndLoss(Project $project, int $year, int $month): array
    {
        $totals = [];

        foreach (LedgerCategory::cases() as $category) {
            $totals[$category->value] = $this->ledgerTotal($project, $category, $year, $month);
        }

        $cost = Money::sum(
            $totals[LedgerCategory::Material->value],
            $totals[LedgerCategory::Subcontract->value],
            $totals[LedgerCategory::Labor->value],
            $totals[LedgerCategory::Overhead->value],
        );

        $revenue = $totals[LedgerCategory::Revenue->value];
        $grossProfit = bcsub($revenue, $cost, Money::SCALE);

        return [
            'revenue' => $revenue,
            'material' => $totals[LedgerCategory::Material->value],
            'subcontract' => $totals[LedgerCategory::Subcontract->value],
            'labor' => $totals[LedgerCategory::Labor->value],
            'overhead' => $totals[LedgerCategory::Overhead->value],
            'total_cost' => $cost,
            'gross_profit' => $grossProfit,
            'margin_percent' => $this->marginPercent($revenue, $grossProfit),
        ];
    }

    /**
     * Every project in the organization, consolidated.
     *
     * @return array<int, array<string, string>>
     */
    public function forOrganization(Organization $organization, int $year, int $month): array
    {
        $rows = [];

        foreach (Project::query()->where('organization_id', $organization->getKey())->orderBy('code')->get() as $project) {
            $rows[] = array_merge(
                ['project_code' => $project->code],
                $this->profitAndLoss($project, $year, $month),
            );
        }

        return $rows;
    }

    /**
     * F16 — what the project needs in cash next month.
     *
     * Five figures, and each is deliberately NOT a P&L figure:
     *
     *   - **committed**: approved purchase orders not yet delivered. Money
     *     promised, in no ledger category until the goods arrive;
     *   - **payables**: AP vouchers raised and not paid. The P&L took the cost
     *     when the goods came; the cash leaves when the cheque is signed;
     *   - **payroll**: an approved register not yet released;
     *   - **expected collections**: what clients still owe on issued invoices —
     *     because a figure counting only outflows would tell a treasurer to
     *     borrow money the client is about to send;
     *   - **retention held**: reported but NOT collectible. The client holds it
     *     until the defects liability period ends, and counting it would have
     *     the treasurer expecting money nobody intends to send for a year.
     *
     * @return array{committed: string, payables: string, payroll: string, retention_held: string, expected_collections: string, net_requirement: string}
     */
    public function cashRequirement(Project $project, int $year, int $month): array
    {
        $committed = $this->committedOnOrders($project);
        $payables = $this->openPayables($project);
        $payroll = $this->approvedPayroll($project);
        $collections = $this->expectedCollections($project);

        $outflows = Money::sum($committed, $payables, $payroll);

        return [
            'committed' => $committed,
            'payables' => $payables,
            'payroll' => $payroll,
            'retention_held' => $this->retention->balanceFor($project),
            'expected_collections' => $collections,
            'net_requirement' => bcsub($outflows, $collections, Money::SCALE),
        ];
    }

    /**
     * One category's total for a period.
     *
     * Summed in bcmath rather than SQL SUM(), the discipline every total in this
     * build follows. Reversing entries are negative rows, so a corrected posting
     * and its reversal net to zero without the report needing to know which rows
     * were corrections.
     */
    public function ledgerTotal(Project $project, LedgerCategory $category, int $year, int $month): string
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        $total = '0.0000';

        $amounts = ProjectCostLedgerEntry::query()
            ->where('project_id', $project->getKey())
            ->where('category', $category)
            ->whereBetween('document_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('amount');

        foreach ($amounts as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    /**
     * Approved orders, less what has already been received against them.
     */
    private function committedOnOrders(Project $project): string
    {
        $committed = '0.0000';

        $orders = PurchaseOrder::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Countersigned])
            ->get();

        foreach ($orders as $order) {
            foreach ($order->lines()->get() as $line) {
                $outstandingUnits = bcsub((string) $line->quantity, (string) $line->quantity_received, Money::SCALE);

                if (bccomp($outstandingUnits, '0', Money::SCALE) <= 0) {
                    continue;
                }

                $committed = Money::sum($committed, Money::multiply($outstandingUnits, (string) $line->unit_price));
            }
        }

        return $committed;
    }

    /**
     * Vouchers raised and not yet paid.
     */
    private function openPayables(Project $project): string
    {
        $total = '0.0000';

        $vouchers = ApVoucher::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', [ApVoucherStatus::Raised, ApVoucherStatus::Approved])
            ->get();

        foreach ($vouchers as $voucher) {
            $total = Money::sum($total, (string) $voucher->net_amount);
        }

        return $total;
    }

    /**
     * An approved register whose money has not gone out.
     */
    private function approvedPayroll(Project $project): string
    {
        $total = '0.0000';

        $runs = PayrollRun::query()
            ->where('organization_id', $project->organization_id)
            ->where('status', PayrollRunStatus::Approved)
            ->get();

        foreach ($runs as $run) {
            $total = Money::sum($total, (string) $run->net_total);
        }

        return $total;
    }

    /**
     * What clients still owe on issued invoices.
     */
    private function expectedCollections(Project $project): string
    {
        $total = '0.0000';

        $invoices = SalesInvoice::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartlyCollected])
            ->get();

        foreach ($invoices as $invoice) {
            $total = Money::sum($total, $this->collections->outstandingFor($invoice));
        }

        return $total;
    }

    /**
     * Gross profit as a share of revenue.
     *
     * Zero rather than a division by nothing. Every project looks like this in
     * its first months — cost accruing, nothing billed yet — and breaking the
     * report there would break it for the projects most worth watching.
     */
    private function marginPercent(string $revenue, string $grossProfit): string
    {
        if (bccomp($revenue, '0', Money::SCALE) <= 0) {
            return '0.00';
        }

        return Money::round(
            bcmul(bcdiv($grossProfit, $revenue, Money::WORKING_SCALE), '100', Money::WORKING_SCALE),
            2,
        );
    }
}
