<?php

namespace App\Domain\Closeout;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Models\Permit;
use App\Models\Project;
use App\Models\Punchlist;
use App\Models\SubstantialCompletion;
use App\Models\TurnoverPack;
use App\Models\TurnoverPackItem;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Slide 9 step 3 — turnover and acceptance, owned by PMO and the client.
 *
 * Its place in the sequence is the design: "substantial completion → punchlist
 * clearing → turnover and acceptance → final billing → demobilization →
 * project close-out." The pack is assembled against the completion certificate,
 * and it cannot be accepted while the punchlist is open — a client accepting a
 * site with defects outstanding accepts the defects with it.
 *
 * **The pack has two kinds of line.** A filed line — as-built drawings, the
 * manuals, the certificate of completion — is satisfied by a person putting a
 * reference on file and signing for it. A register-answered line — warranty
 * certificates, permits — cannot be filed by hand at all: it reads P5-03's
 * warranty register and P2-01's permit register.
 *
 * That distinction is the task. A pack that let a clerk tick "warranty
 * certificates: collected" while subcontracts sit in the register with no
 * certificate against them would be the honour system F4 objected to, rebuilt
 * one phase later and against a deadline where everybody wants the project
 * closed.
 */
class TurnoverService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly WarrantyService $warranties,
        private readonly PunchlistService $punchlists,
    ) {}

    /**
     * Assemble the pack for a certified project.
     *
     * @throws DomainException when the project already has one
     */
    public function assemble(
        SubstantialCompletion $certificate,
        User $by,
        ?CarbonInterface $assembledAt = null,
    ): TurnoverPack {
        $project = $certificate->project()->sole();

        if (TurnoverPack::query()->where('project_id', $project->getKey())->exists()) {
            throw new DomainException(sprintf(
                'Project %s already has a turnover pack. A second one gives the project two acceptance dates, and the defects liability period starts from one of them.',
                $project->code,
            ));
        }

        return DB::transaction(function () use ($project, $certificate, $by, $assembledAt): TurnoverPack {
            $pack = TurnoverPack::query()->create([
                'project_id' => $project->getKey(),
                'substantial_completion_id' => $certificate->getKey(),
                'number' => $this->numbering->next('TOP'),
                'assembled_at' => $assembledAt ?? now(),
                'assembled_by_user_id' => $by->getKey(),
            ]);

            foreach ($this->template() as $sequence => $line) {
                TurnoverPackItem::query()->create([
                    'turnover_pack_id' => $pack->getKey(),
                    'sequence' => $sequence + 1,
                    'document_key' => $line['key'],
                    // Copied onto the row, not read back from config. The pack
                    // records what was asked for AT turnover; editing the
                    // template later must not restate an accepted pack.
                    'label' => $line['label'],
                    'required' => $line['required'],
                    'source' => TurnoverItemSource::from($line['source']),
                ]);
            }

            return $pack->refresh();
        });
    }

    /**
     * Put a document on file against one line.
     *
     * @throws DomainException when the pack is accepted, the line is not on it,
     *                         the line is register-answered, the reference is
     *                         blank, or the line is already filed
     */
    public function file(
        TurnoverPack $pack,
        string $documentKey,
        string $reference,
        User $by,
        ?string $remarks = null,
    ): TurnoverPackItem {
        if ($pack->accepted_at !== null) {
            // The pack that was accepted is the pack the client saw.
            throw new DomainException(sprintf(
                'Turnover pack %s was already accepted on %s. A document added afterwards was not part of what the client signed for.',
                $pack->number,
                $pack->accepted_at->toDateString(),
            ));
        }

        $item = $pack->items()->where('document_key', $documentKey)->first();

        if ($item === null) {
            // Otherwise the checklist is satisfiable by filing anything at all:
            // the count passes and the evidence is still absent.
            throw new DomainException(sprintf(
                'Turnover pack %s does not ask for "%s". It asks for: %s.',
                $pack->number,
                $documentKey,
                implode(', ', $pack->items()->pluck('document_key')->all()),
            ));
        }

        if (! $item->source->isFiled()) {
            throw new DomainException(sprintf(
                '"%s" is answered by the register, not filed by hand. Register the certificates and permits themselves; the line follows.',
                $item->label,
            ));
        }

        if (trim($reference) === '') {
            throw new DomainException(sprintf(
                '"%s" needs a reference. A line filed against nothing is a tick.',
                $item->label,
            ));
        }

        if ($item->filed_at !== null) {
            // Filing is not editing. The second signature would overwrite the
            // first, and the close-out report would name the wrong person for a
            // document they never saw.
            throw new DomainException(sprintf(
                '"%s" is already on file as %s. A correction is its own act, not an overwrite.',
                $item->label,
                $item->reference,
            ));
        }

        $item->update([
            'reference' => trim($reference),
            'filed_at' => now(),
            'filed_by_user_id' => $by->getKey(),
            'remarks' => $remarks,
        ]);

        return $item->refresh();
    }

    /**
     * Every required line still outstanding — all of them, not just the first.
     *
     * A PMO who clears one blocker only to be shown the next, one round trip at
     * a time, is how a turnover date slips by a fortnight.
     *
     * @return array<int, string>
     */
    public function missingFor(TurnoverPack $pack): array
    {
        $project = $pack->project()->sole();
        $missing = [];

        foreach ($pack->items()->where('required', true)->get() as $item) {
            $outstanding = match ($item->source) {
                TurnoverItemSource::Filed => $item->filed_at === null ? $item->label : null,
                TurnoverItemSource::WarrantyRegister => $this->warrantyGap($project, $item->label),
                TurnoverItemSource::PermitRegister => $this->permitGap($project, $item->label),
            };

            if ($outstanding !== null) {
                $missing[] = $outstanding;
            }
        }

        return $missing;
    }

    public function isComplete(TurnoverPack $pack): bool
    {
        return $this->missingFor($pack) === [];
    }

    /**
     * The client accepts the works.
     *
     * @throws DomainException when the pack is already accepted, nobody is
     *                         named for the client, the date is forward, a
     *                         required line is outstanding, or the punchlist is
     *                         not closed
     */
    public function accept(
        TurnoverPack $pack,
        User $by,
        string $clientRepresentative,
        ?CarbonInterface $acceptedAt = null,
        string $remarks = '',
    ): TurnoverPack {
        $acceptedAt ??= now();

        if ($pack->accepted_at !== null) {
            throw new DomainException(sprintf(
                'Turnover pack %s was already accepted on %s by %s.',
                $pack->number,
                $pack->accepted_at->toDateString(),
                (string) $pack->client_representative,
            ));
        }

        if (trim($clientRepresentative) === '') {
            // Slide 9: a named clearer, never a status flag. Acceptance signed
            // by nobody in particular is the flag wearing a date.
            throw new DomainException(
                'Acceptance must name the client representative who signed for it.'
            );
        }

        if ($acceptedAt->isAfter(now())) {
            throw new DomainException(sprintf(
                'An acceptance dated %s has not happened yet. Final billing and the defects liability period both run from this date.',
                $acceptedAt->toDateString(),
            ));
        }

        // The pack's own completeness first — that is the pack's business. The
        // punchlist is an external precondition and is reported second, so the
        // message a PMO sees names the documents they can actually go and get.
        $missing = $this->missingFor($pack);

        if ($missing !== []) {
            throw new DomainException(sprintf(
                'Turnover pack %s cannot be accepted. Outstanding: %s.',
                $pack->number,
                implode(', ', $missing),
            ));
        }

        $project = $pack->project()->sole();
        $punchlist = Punchlist::query()->where('project_id', $project->getKey())->first();

        if ($punchlist === null) {
            // Absence is not permission — the rule P5-01 turned on. "No open
            // items" is true of a list nobody has walked.
            throw new DomainException(sprintf(
                'Project %s has no punchlist. Turnover follows punchlist clearing, and a site nobody walked has no defects only in the sense that nobody looked.',
                $project->code,
            ));
        }

        if (! $this->punchlists->isCleared($punchlist)) {
            throw new DomainException(sprintf(
                'Punchlist %s is still open. A client accepting a site with defects outstanding accepts the defects with it.',
                $punchlist->number,
            ));
        }

        $pack->update([
            'accepted_at' => $acceptedAt,
            'accepted_by_user_id' => $by->getKey(),
            'client_representative' => trim($clientRepresentative),
            'acceptance_remarks' => trim($remarks) === '' ? null : trim($remarks),
        ]);

        return $pack->refresh();
    }

    public function isAccepted(TurnoverPack $pack): bool
    {
        return $pack->accepted_at !== null;
    }

    /**
     * The project's pack, for the tasks downstream of acceptance — final
     * billing, demobilization, retention release.
     */
    public function forProject(Project $project): ?TurnoverPack
    {
        return TurnoverPack::query()->where('project_id', $project->getKey())->first();
    }

    /**
     * Who filed each line and when — slide 9's close-out report, for one pack.
     *
     * @return array<int, array<string, mixed>>
     */
    public function filingReport(TurnoverPack $pack): array
    {
        return $pack->items()->with('filedBy')->get()
            ->map(fn (TurnoverPackItem $item): array => [
                'document' => $item->label,
                'source' => $item->source->value,
                'reference' => $item->reference,
                'filed_at' => $item->filed_at?->toDateTimeString(),
                'filed_by' => $item->filedBy?->name,
            ])
            ->all();
    }

    /**
     * The warranty line, answered by P5-03's register.
     *
     * A project that let no subcontracts owes no certificates, so the line
     * passes — there is nothing to chase. What it refuses is the project that
     * let works and never collected the paper, and it names them, because
     * "warranty certificates outstanding" is not something anybody can act on.
     */
    private function warrantyGap(Project $project, string $label): ?string
    {
        $uncovered = $this->warranties->uncoveredSubcontracts($project);

        if ($uncovered->isEmpty()) {
            return null;
        }

        return sprintf(
            '%s (no certificate on file for %s)',
            $label,
            $uncovered->pluck('number')->implode(', '),
        );
    }

    /**
     * The permit line, answered by P2-01's register.
     *
     * Any permit, not a valid one: an excavation permit that expired when the
     * excavation finished is still a document the client is owed at turnover.
     * Which permits a turnover specifically requires is the same question
     * `PermitService::hasAnyValid()` records as unanswered, so this refuses only
     * the case the build can be sure about — nothing on file at all.
     */
    private function permitGap(Project $project, string $label): ?string
    {
        $any = Permit::query()->where('project_id', $project->getKey())->exists();

        return $any ? null : $label;
    }

    /**
     * @return array<int, array{key: string, label: string, required: bool, source: string}>
     */
    private function template(): array
    {
        /** @var array<int, array{key: string, label: string, required: bool, source: string}> $template */
        $template = config('closeout.turnover_pack', []);

        if ($template === []) {
            throw new DomainException('No turnover pack template is configured. See config/closeout.php.');
        }

        return $template;
    }
}
