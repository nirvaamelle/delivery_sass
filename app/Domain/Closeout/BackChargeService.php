<?php

namespace App\Domain\Closeout;

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\LedgerPoster;
use App\Domain\Support\Money;
use App\Models\BackCharge;
use App\Models\CostCode;
use App\Models\Project;
use App\Models\Punchlist;
use App\Models\PunchlistItem;
use App\Models\Subcontract;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Subcontractor back-charges — F6.
 *
 * The finding: "they reduce a subcontractor's payable and increase project cost
 * — they are ledger postings, and they gate the final billing amount." And
 * PHASE-PLAN.md Part C: "computed at punchlist clearing, applied to final
 * billing before the invoice is raised."
 *
 * The two verbs are the whole design, and they land in different places.
 *
 * **Computed at clearing.** A charge is raised against an item that has been
 * made good, never an open one. Until the defect is put right the cost of
 * putting it right is an estimate, and an estimate in the ledger is a figure the
 * P&L cannot tell apart from money that was actually spent.
 *
 * **Applied at final billing.** One leg is posted here — the COST of the remedy,
 * which is real and already incurred. The RECOVERY is not posted, and that is
 * the decision worth defending. Booking both legs at raise time would credit the
 * project with money nobody has collected and net the exposure to zero on the
 * day it arose. What can actually be recovered depends on how much of the
 * subcontract is still unpaid when the settlement is made, so it is derived,
 * like every other balance in this build.
 *
 * The residue is the number worth having. A defect can cost more to put right
 * than is left owing under the subcontract; the difference is money the project
 * eats, and `unrecoveredFrom()` says how much rather than letting it surface as
 * a surprise at final billing.
 */
class BackChargeService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly LedgerPoster $ledger,
    ) {}

    /**
     * Charge a cleared subcontractor defect back to the subcontractor.
     *
     * @throws DomainException when the item is open, is not a subcontractor's,
     *                         is already charged, or the amount is not positive
     */
    public function raise(
        PunchlistItem $item,
        CostCode $costCode,
        string $amount,
        User $by,
        string $description,
    ): BackCharge {
        if ($item->responsibility !== PunchlistResponsibility::Subcontractor) {
            throw new DomainException(sprintf(
                'Punchlist item %d is on our own forces. There is nobody to charge, and the cost is already in the project through labour and materials.',
                $item->item_no,
            ));
        }

        if ($item->cleared_at === null) {
            // "Computed AT punchlist clearing." The cost of a remedy nobody has
            // carried out is an estimate, and the ledger does not distinguish an
            // estimate from money spent.
            throw new DomainException(sprintf(
                'Punchlist item %d has not been cleared. What making it good costs is not known until somebody has made it good.',
                $item->item_no,
            ));
        }

        if (Money::isZero($amount) || bccomp($amount, '0', Money::SCALE) < 0) {
            throw new DomainException('A back-charge must be a positive amount. A credit to a subcontractor is a different document.');
        }

        if (BackCharge::query()->where('punchlist_item_id', $item->getKey())->exists()) {
            throw new DomainException(sprintf(
                'Punchlist item %d is already charged. A second charge bills the subcontractor twice for one defect.',
                $item->item_no,
            ));
        }

        if (trim($description) === '') {
            throw new DomainException(
                'A back-charge needs a description of the remedy. It is what the subcontractor disputes against, and a bare amount is not a claim.'
            );
        }

        $punchlist = $item->punchlist()->sole();
        $subcontract = $item->subcontract()->sole();
        $project = $punchlist->project()->sole();

        return DB::transaction(function () use ($item, $subcontract, $project, $costCode, $amount, $by, $description): BackCharge {
            $charge = BackCharge::query()->create([
                'punchlist_item_id' => $item->getKey(),
                'subcontract_id' => $subcontract->getKey(),
                'project_id' => $project->getKey(),
                'cost_code_id' => $costCode->getKey(),
                'number' => $this->numbering->next('BC'),
                'amount' => $amount,
                'description' => trim($description),
                // The charge is dated from the CLEARING, not from today. The
                // remedy was carried out then, and the period it belongs to is
                // the period the works consumed it.
                'raised_at' => $item->cleared_at,
                'raised_by_user_id' => $by->getKey(),
            ]);

            $entry = $this->ledger->post(
                project: $project,
                costCode: $costCode,
                // Subcontract, not Material or Labor. The event lives in the
                // subcontract relationship whoever held the trowel, and it is
                // where final billing and the subcontract settlement read it.
                category: LedgerCategory::Subcontract,
                amount: $amount,
                sourceDocument: $charge,
                documentNumber: $charge->number,
                // The BILLING calendar, the same one material cost books
                // against: this is direct project cost, not operating expense.
                cutoffType: CutoffType::Billing,
                documentDate: $item->cleared_at,
                description: sprintf(
                    'Back-charge to %s, punchlist item %d: %s',
                    $subcontract->number,
                    $item->item_no,
                    trim($description),
                ),
            );

            // Stamped after the posting, inside the same transaction. Stamped
            // first, a refused posting would leave a charge claiming to be in a
            // ledger that never took it.
            $charge->update([
                'posted_at' => now(),
                'project_cost_ledger_entry_id' => $entry->getKey(),
            ]);

            return $charge->refresh();
        });
    }

    /**
     * Everything charged against a subcontract.
     */
    public function chargedAgainst(Subcontract $subcontract): string
    {
        $total = '0.0000';

        foreach (BackCharge::query()->where('subcontract_id', $subcontract->getKey())->pluck('amount') as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    /**
     * What is still payable under the subcontract once back-charges come off.
     *
     * Floored at zero. A negative payable is not a payment, it is a receivable —
     * the same rule the AP voucher enforces, and the reason the residue is
     * reported separately rather than folded in here.
     */
    public function netPayable(Subcontract $subcontract): string
    {
        $contract = (string) $subcontract->contract_amount;
        $charged = $this->chargedAgainst($subcontract);

        if (bccomp($charged, $contract, Money::SCALE) >= 0) {
            return Money::round('0');
        }

        return Money::round(bcsub($contract, $charged, Money::WORKING_SCALE));
    }

    /**
     * The part of the back-charges the subcontract cannot cover.
     *
     * F6's "increase project cost", made visible. A defect can cost more to put
     * right than the subcontract is worth, and a system that netted that to zero
     * would hide a real loss until somebody tried to collect it.
     */
    public function unrecoveredFrom(Subcontract $subcontract): string
    {
        $contract = (string) $subcontract->contract_amount;
        $charged = $this->chargedAgainst($subcontract);

        if (bccomp($charged, $contract, Money::SCALE) <= 0) {
            return Money::round('0');
        }

        return Money::round(bcsub($charged, $contract, Money::WORKING_SCALE));
    }

    /**
     * Every back-charge on a project — what final billing reads before the
     * invoice is raised.
     */
    public function totalForProject(Project $project): string
    {
        $total = '0.0000';

        foreach (BackCharge::query()->where('project_id', $project->getKey())->pluck('amount') as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    /**
     * @return Collection<int, BackCharge>
     */
    public function forProject(Project $project): Collection
    {
        return BackCharge::query()
            ->with(['subcontract.vendor', 'punchlistItem'])
            ->where('project_id', $project->getKey())
            ->orderBy('raised_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cleared subcontractor items nobody has priced.
     *
     * Slide 9's structural obligation: "final billing depends on back-charges
     * being computed first, which makes step 2 a hard predecessor of step 4."
     * This is that predecessor as a query — a cleared subcontractor defect with
     * no charge against it is an open question, and P5-05 is entitled to refuse
     * to invoice over it.
     *
     * @return Collection<int, PunchlistItem>
     */
    public function unpricedItems(Punchlist $punchlist): Collection
    {
        return PunchlistItem::query()
            ->where('punchlist_id', $punchlist->getKey())
            ->where('responsibility', PunchlistResponsibility::Subcontractor)
            ->whereNotNull('cleared_at')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('back_charges')
                ->whereColumn('back_charges.punchlist_item_id', 'punchlist_items.id'))
            ->orderBy('item_no')
            ->get();
    }
}
