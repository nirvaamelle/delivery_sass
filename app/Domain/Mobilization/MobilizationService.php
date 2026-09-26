<?php

namespace App\Domain\Mobilization;

use App\Domain\Documents\DocumentLinker;
use App\Domain\Gates\GateFailedException;
use App\Domain\Gates\Gatekeeper;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Models\Mobilization;
use App\Models\MobilizationItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Mobilization — slide 3's step 5, and the document F2 says has no home.
 *
 * **The gate turns on one word: countersigned.** Slide 3's obligation states it
 * outright — a purchase order can be approved internally and not yet
 * countersigned by the vendor, and the mobilization gate tests the second, not
 * the first. Those are two different facts: one says the company decided to
 * buy, the other says the supplier agreed to sell. Mobilizing on the first puts
 * a crew, a compound and a generator on site against an order the other party
 * never accepted — and every peso of that is spent before anybody notices.
 *
 * The gate itself is declared in `GateServiceProvider` alongside every other
 * gate in the system, rather than as an `if` in this file. That is P0-10's rule:
 * the full set of preconditions the business runs on should be readable top to
 * bottom in one place.
 *
 * **The checklist is created with the mobilization, from the enum.** A list
 * somebody assembles by hand is a list that can be assembled short, and the
 * item left off is the one nobody remembers was meant to be there.
 */
class MobilizationService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly DocumentLinker $links,
        private readonly Gatekeeper $gates,
        private readonly PermitService $permits,
    ) {}

    /**
     * Open a mobilization against a countersigned purchase order.
     *
     * @throws GateFailedException when the order is not countersigned
     */
    public function mobilize(PurchaseOrder $order, CarbonInterface $mobilizedOn, ?string $remarks = null): Mobilization
    {
        $this->gates->assert($order, MobilizationTransition::Mobilize->value);

        return DB::transaction(function () use ($order, $mobilizedOn, $remarks): Mobilization {
            $mobilization = Mobilization::query()->create([
                'project_id' => $order->project_id,
                'purchase_order_id' => $order->getKey(),
                'number' => $this->numbering->next('MOB'),
                'status' => MobilizationStatus::InProgress,
                'mobilized_on' => $mobilizedOn,
                'remarks' => $remarks,
            ]);

            foreach (ChecklistItem::cases() as $item) {
                $mobilization->items()->create([
                    'item' => $item,
                    'required' => $item->isRequired(),
                ]);
            }

            $this->links->link($order, $mobilization);

            return $mobilization->refresh();
        });
    }

    /**
     * Tick one checklist item.
     *
     * @throws DomainException when the item is already done, the mobilization is
     *                         closed, or the item's own condition is unmet
     */
    public function completeItem(
        Mobilization $mobilization,
        ChecklistItem $item,
        ?User $by = null,
        ?string $remarks = null,
    ): MobilizationItem {
        if ($mobilization->status !== MobilizationStatus::InProgress) {
            throw new DomainException(sprintf(
                'Mobilization %s is %s. A closed mobilization cannot gain new work.',
                $mobilization->number,
                $mobilization->status->value,
            ));
        }

        $row = $mobilization->items()->where('item', $item)->first();

        if ($row === null) {
            throw new DomainException(sprintf('%s is not on mobilization %s.', $item->label(), $mobilization->number));
        }

        if ($row->completed_at !== null) {
            throw new DomainException(sprintf('%s was already completed on %s.', $item->label(), $row->completed_at->toDateString()));
        }

        /*
         * The permits item is the one with teeth. Slide 3 says "site setup and
         * permits secured", and a box anybody's pen can reach records intent
         * rather than fact — so this one reads an actual permit with an actual
         * expiry, and an expired one does not count.
         */
        if ($item === ChecklistItem::PermitsSecured
            && ! $this->permits->hasAnyValid($mobilization->project()->sole())) {
            throw new DomainException(sprintf(
                'Project %d holds no permit in force today. "Permits secured" is a fact about the site, not a box on a form.',
                $mobilization->project_id,
            ));
        }

        $row->update([
            'completed_at' => now(),
            'completed_by_user_id' => $by?->getKey(),
            'remarks' => $remarks,
        ]);

        return $row->refresh();
    }

    /**
     * Close a mobilization.
     *
     * @throws DomainException when required items are still outstanding
     */
    public function complete(Mobilization $mobilization, ?User $by = null): Mobilization
    {
        if ($mobilization->status !== MobilizationStatus::InProgress) {
            throw new DomainException(sprintf(
                'Mobilization %s is already %s.',
                $mobilization->number,
                $mobilization->status->value,
            ));
        }

        $outstanding = $this->outstandingFor($mobilization);

        if ($outstanding !== []) {
            // Every outstanding item, not just the first. A site manager who
            // clears one blocker only to be shown the next, one round trip at a
            // time, is how a mobilization slips a week.
            throw new DomainException(sprintf(
                'Mobilization %s cannot be completed. Outstanding: %s.',
                $mobilization->number,
                implode(', ', $outstanding),
            ));
        }

        $mobilization->update([
            'status' => MobilizationStatus::Completed,
            'completed_at' => now(),
            'completed_by_user_id' => $by?->getKey(),
        ]);

        return $mobilization->refresh();
    }

    /**
     * Which required items are still open.
     *
     * @return array<int, string>
     */
    public function outstandingFor(Mobilization $mobilization): array
    {
        return $mobilization->items()
            ->where('required', true)
            ->whereNull('completed_at')
            ->get()
            ->map(fn (MobilizationItem $row): string => $row->item->label())
            ->all();
    }

    /**
     * Abandon a mobilization, with its reason.
     *
     * @throws DomainException when the reason is blank
     */
    public function cancel(Mobilization $mobilization, string $reason): Mobilization
    {
        if (trim($reason) === '') {
            throw new DomainException('A cancelled mobilization needs a reason — it is what explains the cost already incurred.');
        }

        $mobilization->update([
            'status' => MobilizationStatus::Cancelled,
            'remarks' => $reason,
        ]);

        return $mobilization->refresh();
    }
}
