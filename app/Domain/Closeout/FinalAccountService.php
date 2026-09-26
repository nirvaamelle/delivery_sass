<?php

namespace App\Domain\Closeout;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Support\Money;
use App\Models\Contract;
use App\Models\FinalAccount;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use App\Models\PurchaseOrder;
use App\Models\Subcontract;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorScorecard;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The final project P&L, the forecast to completion, and the scorecards that
 * have to be on file before either is signed — slide 9's financial close and
 * the last line of its people-and-assets panel.
 *
 * **Life to date, not a month.** P4-08's `profitAndLoss()` answers "what did
 * this project do in May", which is what a monthly consolidation asks. The
 * close-out question is what the project made over all of it, and a report that
 * could only be read a month at a time gets summed by hand into a spreadsheet
 * nobody can audit.
 *
 * **Filed, not derived.** This is the only figure in the phase kept as a stored
 * snapshot, and the reason is narrow. The P&L half is reproducible: the ledger
 * is append-only, so life-to-date sums cannot move. The forecast half is not —
 * it reads open commitments, and those change with every order raised or
 * cancelled. A close-out report whose numbers differ next quarter is not a
 * record of what the project made.
 *
 * **Scorecards gate the filing.** Slide 9 puts them in the same close-out, and
 * the final account is the document that ends it: file over an unrated
 * subcontractor and the only assessment anybody will ever make of them is lost,
 * along with the input F12's suspension rules read.
 */
class FinalAccountService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
    ) {}

    /**
     * The P&L over the life of the project.
     *
     * @return array{revenue: string, material: string, subcontract: string, labor: string, overhead: string, total_cost: string, gross_profit: string, margin_percent: string}
     */
    public function profitAndLoss(Project $project): array
    {
        $totals = [];

        foreach (LedgerCategory::cases() as $category) {
            $totals[$category->value] = $this->lifetimeTotal($project, $category);
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
     * What the project will have made when everything promised has landed.
     *
     * `committed_cost` is the figure that makes this a forecast rather than a
     * restatement: an approved order not yet delivered is in no ledger category
     * — the cost arrives with the goods — but ignoring it under-reads the final
     * outturn by exactly what somebody has already promised to spend.
     *
     * @return array{contract_sum: string, revenue_to_date: string, revenue_remaining: string, cost_to_date: string, committed_cost: string, forecast_final_cost: string, forecast_gross_profit: string, forecast_margin_percent: string}
     */
    public function forecastToCompletion(Project $project): array
    {
        $pl = $this->profitAndLoss($project);
        $contract = $this->contractFor($project);
        $contractSum = $contract === null ? '0.0000' : (string) $contract->contract_sum;

        $revenueToDate = $pl['revenue'];
        $costToDate = $pl['total_cost'];
        $committed = $this->committedCost($project);

        // Floored at zero. Over-billing happens — a variation billed before the
        // contract sum was revised — and a negative "remaining" reads as money
        // owed back rather than as a contract that needs restating.
        $remaining = bccomp($revenueToDate, $contractSum, Money::SCALE) >= 0
            ? Money::round('0')
            : bcsub($contractSum, $revenueToDate, Money::SCALE);

        $forecastCost = Money::sum($costToDate, $committed);
        $forecastProfit = bcsub($contractSum, $forecastCost, Money::SCALE);

        return [
            'contract_sum' => $contractSum,
            'revenue_to_date' => $revenueToDate,
            'revenue_remaining' => $remaining,
            'cost_to_date' => $costToDate,
            'committed_cost' => $committed,
            'forecast_final_cost' => $forecastCost,
            'forecast_gross_profit' => $forecastProfit,
            'forecast_margin_percent' => $this->marginPercent($contractSum, $forecastProfit),
        ];
    }

    /**
     * File the final account.
     *
     * @throws DomainException when the project already has one, has no
     *                         contract, or a vendor on it is unrated
     */
    public function file(Project $project, User $by, ?string $remarks = null): FinalAccount
    {
        if (FinalAccount::query()->where('project_id', $project->getKey())->exists()) {
            throw new DomainException(sprintf(
                'Project %s already has a final account. Two of them are two answers to what the project made.',
                $project->code,
            ));
        }

        $contract = $this->contractFor($project);

        if ($contract === null) {
            throw new DomainException(sprintf(
                'Project %s has no contract, so there is no contract sum to forecast against.',
                $project->code,
            ));
        }

        $unrated = $this->unratedVendors($project);

        if ($unrated->isNotEmpty()) {
            throw new DomainException(sprintf(
                'These suppliers worked on %s and have no scorecard: %s. Filing over them loses the only assessment anybody will make of them, and the suspension rules read it.',
                $project->code,
                $unrated->pluck('code')->implode(', '),
            ));
        }

        $pl = $this->profitAndLoss($project);
        $forecast = $this->forecastToCompletion($project);

        return DB::transaction(fn (): FinalAccount => FinalAccount::query()->create([
            'project_id' => $project->getKey(),
            'contract_id' => $contract->getKey(),
            'number' => $this->numbering->next('FA'),
            'revenue' => $pl['revenue'],
            'material_cost' => $pl['material'],
            'subcontract_cost' => $pl['subcontract'],
            'labor_cost' => $pl['labor'],
            'overhead_cost' => $pl['overhead'],
            'total_cost' => $pl['total_cost'],
            'gross_profit' => $pl['gross_profit'],
            'margin_percent' => $pl['margin_percent'],
            'contract_sum' => $forecast['contract_sum'],
            'revenue_remaining' => $forecast['revenue_remaining'],
            'committed_cost' => $forecast['committed_cost'],
            'forecast_final_cost' => $forecast['forecast_final_cost'],
            'forecast_gross_profit' => $forecast['forecast_gross_profit'],
            'filed_at' => now(),
            'filed_by_user_id' => $by->getKey(),
            'remarks' => $remarks === null || trim($remarks) === '' ? null : trim($remarks),
        ]));
    }

    public function forProject(Project $project): ?FinalAccount
    {
        return FinalAccount::query()->where('project_id', $project->getKey())->first();
    }

    /**
     * Suppliers who worked on this project and hold no scorecard at all.
     *
     * "Worked on" means a purchase order or a subcontract — the two ways a
     * supplier is engaged. A vendor rated on one project's order is still
     * unrated here if their work on THIS one was a subcontract, because the
     * card belongs to the engagement rather than to the company.
     *
     * @return Collection<int, Vendor>
     */
    public function unratedVendors(Project $project): Collection
    {
        $unrated = [];

        $orders = PurchaseOrder::query()
            ->where('project_id', $project->getKey())
            ->whereNotIn('status', [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled])
            ->get();

        foreach ($orders as $order) {
            if (! VendorScorecard::query()->where('purchase_order_id', $order->getKey())->exists()) {
                $unrated[(int) $order->vendor_id] = true;
            }
        }

        $subcontracts = Subcontract::query()->where('project_id', $project->getKey())->get();

        foreach ($subcontracts as $subcontract) {
            if (! VendorScorecard::query()->where('subcontract_id', $subcontract->getKey())->exists()) {
                $unrated[(int) $subcontract->vendor_id] = true;
            }
        }

        return Vendor::query()
            ->whereIn('id', array_keys($unrated) === [] ? [0] : array_keys($unrated))
            ->orderBy('code')
            ->get();
    }

    /**
     * One category, over every period the project has ever posted in.
     *
     * Summed in bcmath rather than SQL SUM(), the discipline every total in
     * this build follows. Reversing entries are negative rows, so a correction
     * and its reversal net to zero without the report knowing which was which.
     */
    public function lifetimeTotal(Project $project, LedgerCategory $category): string
    {
        $total = '0.0000';

        $amounts = ProjectCostLedgerEntry::query()
            ->where('project_id', $project->getKey())
            ->where('category', $category)
            ->pluck('amount');

        foreach ($amounts as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    /**
     * Approved orders not yet delivered — promised, and not yet cost.
     */
    private function committedCost(Project $project): string
    {
        $committed = '0.0000';

        $orders = PurchaseOrder::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Countersigned])
            ->get();

        foreach ($orders as $order) {
            foreach ($order->lines()->get() as $line) {
                $outstanding = bcsub((string) $line->quantity, (string) $line->quantity_received, Money::SCALE);

                if (bccomp($outstanding, '0', Money::SCALE) <= 0) {
                    continue;
                }

                $committed = Money::sum($committed, Money::multiply($outstanding, (string) $line->unit_price));
            }
        }

        return $committed;
    }

    private function contractFor(Project $project): ?Contract
    {
        return Contract::query()->where('project_id', $project->getKey())->orderBy('id')->first();
    }

    /**
     * Margin as a percentage of revenue, or zero when there is none.
     *
     * A project with no revenue has no margin; reporting one would mean
     * dividing by zero, and reporting "100%" on a project that has billed
     * nothing is worse than reporting nothing.
     */
    private function marginPercent(string $revenue, string $grossProfit): string
    {
        if (Money::isZero($revenue)) {
            return '0.00';
        }

        return bcdiv(bcmul($grossProfit, '100', Money::WORKING_SCALE), $revenue, 2);
    }
}
