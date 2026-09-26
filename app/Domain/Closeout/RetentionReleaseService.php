<?php

namespace App\Domain\Closeout;

use App\Domain\Billing\RetentionService;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Models\Contract;
use App\Models\Project;
use App\Models\RetentionRelease;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retention released at the end of the defects liability period — and,
 * separately, collected.
 *
 * **The distinction is the point.** P2-07 built the retention ledger and a
 * `release()` that moves the balance. Read on its own that conflates two events
 * which are weeks apart in practice: the client agreeing the period has run,
 * and the money actually arriving. Slide 9's closing rule turns on the second —
 * "a project stays open in the books until retention is collected" — so a
 * project closed on the first is closed on a promise.
 *
 * The claim is therefore its own document, and the ledger movement is written
 * when the money lands. The balance then means exactly what it says: what the
 * client is still holding.
 *
 * The movement goes through `RetentionService::release()` rather than around
 * it. That service owns the ledger; a second writer would be a second set of
 * rules about what may move the balance, and the two would diverge on the first
 * change to either.
 */
class RetentionReleaseService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly RetentionService $retention,
    ) {}

    /**
     * Claim retention now the defects liability period has run.
     *
     * @throws DomainException when the period has not ended, the amount is not
     *                         positive or exceeds what is unclaimed, or a claim
     *                         is already outstanding
     */
    public function claim(
        Contract $contract,
        string $amount,
        User $by,
        ?CarbonInterface $claimedOn = null,
    ): RetentionRelease {
        if (Money::isZero($amount) || bccomp($amount, '0', Money::SCALE) < 0) {
            throw new DomainException('A retention claim must be a positive amount.');
        }

        $endsOn = $this->retention->defectsLiabilityEndsOn($contract);

        if ($endsOn === null) {
            // A missing completion date is not permission — the same rule the
            // cutoff calendar follows.
            throw new DomainException(sprintf(
                'Contract %s has no completion date, so its defects liability period has not started.',
                $contract->number,
            ));
        }

        $claimedOn = ($claimedOn ?? now())->copy()->startOfDay();

        if ($claimedOn->lt($endsOn)) {
            throw new DomainException(sprintf(
                'The defects liability period on contract %s runs until %s. Claiming early asks the client for money they have not agreed to release.',
                $contract->number,
                $endsOn->toDateString(),
            ));
        }

        $project = $contract->project()->sole();
        $outstanding = $this->outstandingClaimFor($project);

        if ($outstanding !== null) {
            // Two claims open at once is one of them paid twice or one chased
            // forever. A second claim after the first is collected is ordinary,
            // and the test for partial release proves it.
            throw new DomainException(sprintf(
                'Retention on project %s is already claimed under %s, %s uncollected.',
                $project->code,
                $outstanding->number,
                $outstanding->amount,
            ));
        }

        $held = $this->retention->balanceFor($project);

        if (Money::greaterThan($amount, $held)) {
            throw new DomainException(sprintf(
                'Retention held is %s; this claim is for %s. Claiming more than was withheld invents money the client never held.',
                $held,
                $amount,
            ));
        }

        return RetentionRelease::query()->create([
            'project_id' => $project->getKey(),
            'contract_id' => $contract->getKey(),
            'number' => $this->numbering->next('RR'),
            'amount' => $amount,
            'claimed_on' => $claimedOn,
            'claimed_by_user_id' => $by->getKey(),
        ]);
    }

    /**
     * The money arrives. This is the movement, and the thing that lets a
     * project close.
     *
     * @throws DomainException when already collected, the reference is blank,
     *                         or the money is dated before the claim
     */
    public function collect(
        RetentionRelease $claim,
        CarbonInterface $receivedOn,
        string $reference,
        User $by,
    ): RetentionRelease {
        if ($claim->collected_on !== null) {
            throw new DomainException(sprintf(
                'Retention claim %s was already collected on %s.',
                $claim->number,
                $claim->collected_on->toDateString(),
            ));
        }

        if (trim($reference) === '') {
            throw new DomainException(
                'A retention collection needs a reference — the receipt the money arrived against. An unreferenced collection cannot be reconciled to a bank line.'
            );
        }

        $receivedOn = $receivedOn->copy()->startOfDay();

        if ($receivedOn->lt($claim->claimed_on)) {
            throw new DomainException(sprintf(
                'Money received %s, before the claim was raised on %s.',
                $receivedOn->toDateString(),
                $claim->claimed_on->toDateString(),
            ));
        }

        return DB::transaction(function () use ($claim, $receivedOn, $reference, $by): RetentionRelease {
            // Through the service that owns the ledger, which re-checks the DLP
            // and the balance on its own terms.
            $entry = $this->retention->release(
                $claim->contract()->sole(),
                (string) $claim->amount,
                trim($reference),
                $by,
            );

            $claim->update([
                'collected_on' => $receivedOn,
                'collection_reference' => trim($reference),
                'collected_by_user_id' => $by->getKey(),
                'retention_entry_id' => $entry->getKey(),
            ]);

            return $claim->refresh();
        });
    }

    /**
     * The claim raised and not yet paid, if there is one.
     */
    public function outstandingClaimFor(Project $project): ?RetentionRelease
    {
        return RetentionRelease::query()
            ->where('project_id', $project->getKey())
            ->whereNull('collected_on')
            ->orderBy('claimed_on')
            ->first();
    }

    /**
     * @return Collection<int, RetentionRelease>
     */
    public function forProject(Project $project): Collection
    {
        return RetentionRelease::query()
            ->where('project_id', $project->getKey())
            ->orderBy('claimed_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Is every peso of retention on this project actually in the bank?
     *
     * Both halves matter. A zero balance with a claim outstanding cannot
     * happen through this service, but it is exactly what an importer or a
     * direct call to `release()` would leave behind, and the project close is
     * the last place that should be discovered.
     */
    public function isFullyCollected(Project $project): bool
    {
        return Money::isZero($this->retention->balanceFor($project))
            && $this->outstandingClaimFor($project) === null;
    }
}
