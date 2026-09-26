<?php

namespace App\Domain\Closeout;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Models\Punchlist;
use App\Models\PunchlistItem;
use App\Models\Subcontract;
use App\Models\SubstantialCompletion;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Slide 9 step 2 — punchlist clearing, owned by Site/QC.
 *
 * The sentence this service exists to satisfy:
 *
 *   "Close-out is a checklist with NAMED CLEARERS PER LINE, not a status flag."
 *
 * So clearing is never a boolean. It writes a time, a name and a note together,
 * and the database refuses any two of the three without the other — because a
 * control that lives only here is not a control.
 *
 * Two further judgements are worth stating, because both could reasonably have
 * gone the other way:
 *
 *   - **An empty punchlist is not a cleared one.** "No open items" is true of a
 *     list nobody has walked. Closing is therefore its own act with a name on
 *     it, and `isCleared()` reads `closed_at` rather than counting rows.
 *   - **Clearing is not editing.** An item already cleared is refused rather
 *     than overwritten: the second signature would replace the first, and the
 *     close-out report would name the wrong person for work they did not check.
 */
class PunchlistService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
    ) {}

    /**
     * Issue the punchlist against a certificate of substantial completion.
     *
     * @throws DomainException when the certificate already has one
     */
    public function issue(SubstantialCompletion $certificate, User $by, ?CarbonInterface $issuedOn = null): Punchlist
    {
        if (Punchlist::query()->where('substantial_completion_id', $certificate->getKey())->exists()) {
            throw new DomainException(sprintf(
                'Certificate %s already has a punchlist. Two lists of defects against one completion is two answers to what is outstanding.',
                $certificate->number,
            ));
        }

        $issuedOn ??= now();

        if ($issuedOn->lessThan($certificate->certified_on)) {
            // The punchlist records what was outstanding AT substantial
            // completion. One dated before it was written against a site in a
            // different condition.
            throw new DomainException(sprintf(
                'A punchlist cannot be issued before substantial completion on %s.',
                $certificate->certified_on->toDateString(),
            ));
        }

        return DB::transaction(fn (): Punchlist => Punchlist::query()->create([
            'substantial_completion_id' => $certificate->getKey(),
            'project_id' => $certificate->project_id,
            'number' => $this->numbering->next('PL'),
            'issued_on' => $issuedOn,
            'issued_by_user_id' => $by->getKey(),
        ]));
    }

    /**
     * Add a defect to the list.
     *
     * @throws DomainException when the description is empty, the punchlist is
     *                         closed, or a subcontractor item names no
     *                         subcontract of this project
     */
    public function addItem(
        Punchlist $punchlist,
        string $description,
        ?string $location = null,
        PunchlistResponsibility $responsibility = PunchlistResponsibility::OwnForces,
        ?Subcontract $subcontract = null,
        ?CarbonInterface $dueOn = null,
    ): PunchlistItem {
        if (trim($description) === '') {
            throw new DomainException(
                'A punchlist item needs a description. It is what the site fixes against, and a blank line clears itself.'
            );
        }

        if ($punchlist->closed_at !== null) {
            throw new DomainException(sprintf(
                'Punchlist %s was closed on %s. A defect found afterwards is a warranty claim, not a punchlist item.',
                $punchlist->number,
                $punchlist->closed_at->toDateString(),
            ));
        }

        $this->assertAttributed($punchlist, $responsibility, $subcontract);

        return DB::transaction(fn (): PunchlistItem => PunchlistItem::query()->create([
            'punchlist_id' => $punchlist->getKey(),
            'item_no' => $this->nextItemNumber($punchlist),
            'description' => trim($description),
            'location' => $location,
            'responsibility' => $responsibility,
            'subcontract_id' => $subcontract?->getKey(),
            'raised_on' => now(),
            'due_on' => $dueOn,
        ]));
    }

    /**
     * Clear one line — a time, a name and a note, written together.
     *
     * @throws DomainException when the item is already cleared or the note is empty
     */
    public function clear(PunchlistItem $item, User $by, string $note, ?CarbonInterface $at = null): PunchlistItem
    {
        if ($item->cleared_at !== null) {
            throw new DomainException(sprintf(
                'Item %d is already cleared. Clearing again would replace the name of whoever checked it.',
                $item->item_no,
            ));
        }

        if (trim($note) === '') {
            throw new DomainException(
                'Clearing an item needs a note saying what was done. A signature against a blank line proves somebody clicked, not that somebody looked.'
            );
        }

        $item->update([
            'cleared_at' => $at ?? now(),
            'cleared_by_user_id' => $by->getKey(),
            'clearance_note' => trim($note),
        ]);

        return $item->refresh();
    }

    /**
     * Close the punchlist — the act turnover and final billing read.
     *
     * @throws DomainException when items are still open, or it is already closed
     */
    public function close(Punchlist $punchlist, User $by, string $note): Punchlist
    {
        if ($punchlist->closed_at !== null) {
            throw new DomainException(sprintf(
                'Punchlist %s was already closed on %s.',
                $punchlist->number,
                $punchlist->closed_at->toDateString(),
            ));
        }

        if (trim($note) === '') {
            throw new DomainException(
                'Closing a punchlist needs a note. It is the last thing anybody reads before turnover.'
            );
        }

        $open = $this->openItems($punchlist);

        if ($open->isNotEmpty()) {
            throw new DomainException(sprintf(
                'Punchlist %s has %d open item%s: %s. Closing over them would report defects as cleared that nobody cleared.',
                $punchlist->number,
                $open->count(),
                $open->count() === 1 ? '' : 's',
                $open->pluck('item_no')->implode(', '),
            ));
        }

        $punchlist->update([
            'closed_at' => now(),
            'closed_by_user_id' => $by->getKey(),
            'closure_note' => trim($note),
        ]);

        return $punchlist->refresh();
    }

    /**
     * @return Collection<int, PunchlistItem>
     */
    public function openItems(Punchlist $punchlist): Collection
    {
        return PunchlistItem::query()
            ->where('punchlist_id', $punchlist->getKey())
            ->whereNull('cleared_at')
            ->orderBy('item_no')
            ->get();
    }

    /**
     * Whether the punchlist has been signed off — not whether it happens to be
     * empty. The distinction is the whole point: a list nobody walked has no
     * open items either.
     */
    public function isCleared(Punchlist $punchlist): bool
    {
        return $punchlist->closed_at !== null;
    }

    /**
     * Who cleared each item and when — slide 9's close-out report, for one list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function clearanceReport(Punchlist $punchlist): array
    {
        return PunchlistItem::query()
            ->with(['clearedBy', 'subcontract.vendor'])
            ->where('punchlist_id', $punchlist->getKey())
            ->whereNotNull('cleared_at')
            ->orderBy('item_no')
            ->get()
            ->map(fn (PunchlistItem $item): array => [
                'item_no' => $item->item_no,
                'description' => $item->description,
                'responsibility' => $item->responsibility->value,
                'subcontractor' => $item->subcontract?->vendor?->name,
                'cleared_by' => $item->clearedBy?->name,
                'cleared_at' => $item->cleared_at?->toDateTimeString(),
                'note' => $item->clearance_note,
            ])
            ->all();
    }

    /**
     * @throws DomainException
     */
    private function assertAttributed(
        Punchlist $punchlist,
        PunchlistResponsibility $responsibility,
        ?Subcontract $subcontract,
    ): void {
        if ($responsibility === PunchlistResponsibility::Subcontractor && $subcontract === null) {
            // F6's hook. A back-charge computed at clearing needs to know whose
            // payable it reduces; an item blamed on "the subcontractor" in prose
            // charges nobody.
            throw new DomainException(
                'An item a subcontractor has to make good must name the subcontract. A back-charge with nobody to charge is a note in a file.'
            );
        }

        if ($responsibility !== PunchlistResponsibility::Subcontractor && $subcontract !== null) {
            throw new DomainException(
                'An item on our own forces names no subcontract. The cost is already in the project through labour and materials; charging it again would double it.'
            );
        }

        if ($subcontract !== null && (int) $subcontract->project_id !== (int) $punchlist->project_id) {
            // Cross-project attribution back-charges a subcontractor for defects
            // on works they were never let, and every row still looks well-formed.
            throw new DomainException(sprintf(
                'Subcontract %s belongs to another project than this punchlist.',
                $subcontract->number,
            ));
        }
    }

    private function nextItemNumber(Punchlist $punchlist): int
    {
        return (int) PunchlistItem::query()
            ->where('punchlist_id', $punchlist->getKey())
            ->max('item_no') + 1;
    }
}
