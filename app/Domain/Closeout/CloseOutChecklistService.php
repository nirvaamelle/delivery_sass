<?php

namespace App\Domain\Closeout;

use App\Domain\Billing\CollectionService;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Projects\ProjectStatus;
use App\Models\CloseOutChecklist;
use App\Models\CloseOutChecklistItem;
use App\Models\Permit;
use App\Models\Project;
use App\Models\SalesInvoice;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Slide 9's close-out checklist.
 *
 * The obligation PHASE-PLAN.md puts at the head of this whole phase: **"Close-
 * out is a checklist with named clearers per line, not a status flag."** And
 * the exit gate's second sentence: "the close-out report names who cleared each
 * item and when."
 *
 * **Every line takes a name, a time and a note, and no line has a status
 * column.** That is the rule expressed as schema rather than as convention —
 * `cleared_at` is the clearance, and there is nowhere for a boolean to disagree
 * with it.
 *
 * **A line whose fact the build already holds cannot be signed while that fact
 * is false.** This goes beyond what slide 9 asks, deliberately. The slide's
 * rule stops a status flag; it does not stop a signature that is not true, and
 * on the last day of a project everybody wants the line signed. So `retention
 * collected` is refused while a claim sits unpaid, and `site demobilized` while
 * plant is still out. The person still signs — nobody is replaced by a query —
 * but they cannot certify something this system knows to be untrue.
 *
 * Lines the build cannot check say `manual` out loud rather than pretending.
 * P5-09 took two of them out of that category — the final P&L and the filed
 * scorecards — by making them facts the build holds; the config changed and
 * this class only learned two more sources.
 */
class CloseOutChecklistService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly TurnoverService $turnovers,
        private readonly WarrantyService $warranties,
        private readonly RetentionReleaseService $releases,
        private readonly DemobilizationService $demobilizations,
        private readonly FinalBillingService $finalBillings,
        private readonly CollectionService $collections,
        private readonly FinalAccountService $finalAccounts,
    ) {}

    /**
     * Open the checklist for a project.
     *
     * @throws DomainException when the project already has one
     */
    public function open(Project $project, User $by, ?CarbonInterface $openedAt = null): CloseOutChecklist
    {
        if (CloseOutChecklist::query()->where('project_id', $project->getKey())->exists()) {
            throw new DomainException(sprintf(
                'Project %s already has a close-out checklist. Two of them are two close-out reports, and the project would close over one of them.',
                $project->code,
            ));
        }

        return DB::transaction(function () use ($project, $by, $openedAt): CloseOutChecklist {
            $checklist = CloseOutChecklist::query()->create([
                'project_id' => $project->getKey(),
                'number' => $this->numbering->next('COC'),
                'opened_at' => $openedAt ?? now(),
                'opened_by_user_id' => $by->getKey(),
            ]);

            foreach ($this->template() as $sequence => $line) {
                CloseOutChecklistItem::query()->create([
                    'close_out_checklist_id' => $checklist->getKey(),
                    'sequence' => $sequence + 1,
                    'panel' => CloseOutPanel::from($line['panel']),
                    'document_key' => $line['key'],
                    'label' => $line['label'],
                    'evidence' => CloseOutEvidence::from($line['evidence']),
                ]);
            }

            return $checklist->refresh();
        });
    }

    /**
     * Sign one line off.
     *
     * @throws DomainException when the project is closed, the line is not on
     *                         the checklist, it is already cleared, the note is
     *                         blank, or the evidence does not support it
     */
    public function clear(
        CloseOutChecklist $checklist,
        string $documentKey,
        User $by,
        string $note,
    ): CloseOutChecklistItem {
        $project = $checklist->project()->sole();

        if ($project->status === ProjectStatus::Closed) {
            throw new DomainException(sprintf(
                'Project %s is closed. A line signed afterwards was not part of the report the project closed on.',
                $project->code,
            ));
        }

        $item = $checklist->items()->where('document_key', $documentKey)->first();

        if ($item === null) {
            throw new DomainException(sprintf(
                'Checklist %s does not have a line "%s". It has: %s.',
                $checklist->number,
                $documentKey,
                implode(', ', $checklist->items()->pluck('document_key')->all()),
            ));
        }

        if ($item->cleared_at !== null) {
            // Clearing is not editing — the rule P5-01 established. The second
            // signature overwrites the first and the report names the wrong
            // person for work they never checked.
            throw new DomainException(sprintf(
                '"%s" was already cleared on %s.',
                $item->label,
                $item->cleared_at->toDateString(),
            ));
        }

        if (trim($note) === '') {
            throw new DomainException(sprintf(
                '"%s" needs a note saying what was checked. It is what the close-out report says, and a signature against nothing is a tick with a name on it.',
                $item->label,
            ));
        }

        $failure = $this->evidenceFailureFor($project, $item->evidence);

        if ($failure !== null) {
            throw new DomainException(sprintf(
                '"%s" cannot be certified: %s',
                $item->label,
                $failure,
            ));
        }

        $item->update([
            'cleared_at' => now(),
            'cleared_by_user_id' => $by->getKey(),
            'note' => trim($note),
        ]);

        return $item->refresh();
    }

    /**
     * Every line nobody has signed — all of them, not the first.
     *
     * @return array<int, string>
     */
    public function outstandingFor(CloseOutChecklist $checklist): array
    {
        return $checklist->items()
            ->whereNull('cleared_at')
            ->get()
            ->map(fn (CloseOutChecklistItem $item): string => $item->label)
            ->all();
    }

    public function isComplete(CloseOutChecklist $checklist): bool
    {
        return $this->outstandingFor($checklist) === [];
    }

    public function forProject(Project $project): ?CloseOutChecklist
    {
        return CloseOutChecklist::query()->where('project_id', $project->getKey())->first();
    }

    /**
     * The close-out report: who cleared each item and when.
     *
     * The exit gate's second sentence, as a query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function report(CloseOutChecklist $checklist): array
    {
        return $checklist->items()->with('clearedBy')->get()
            ->map(fn (CloseOutChecklistItem $item): array => [
                'panel' => $item->panel->value,
                'key' => $item->document_key,
                'item' => $item->label,
                'evidence' => $item->evidence->value,
                'cleared_at' => $item->cleared_at?->toDateTimeString(),
                'cleared_by' => $item->clearedBy?->name,
                'note' => $item->note,
            ])
            ->all();
    }

    /**
     * Why this line cannot be certified, or null if it can.
     *
     * Each branch asks the service that owns the fact rather than querying
     * around it — the same rule the rest of the phase follows, and the reason a
     * change to what "collected" means cannot leave the checklist behind.
     */
    private function evidenceFailureFor(Project $project, CloseOutEvidence $evidence): ?string
    {
        return match ($evidence) {
            CloseOutEvidence::Manual => null,

            CloseOutEvidence::TurnoverAccepted => $this->turnoverFailure($project),

            CloseOutEvidence::WarrantiesRegistered => $this->warranties->uncoveredSubcontracts($project)->isEmpty()
                ? null
                : sprintf(
                    'no warranty certificate is on file for %s.',
                    $this->warranties->uncoveredSubcontracts($project)->pluck('number')->implode(', '),
                ),

            CloseOutEvidence::PermitsOnFile => Permit::query()->where('project_id', $project->getKey())->exists()
                ? null
                : 'no permit is on file for this project at all.',

            CloseOutEvidence::FinalBillingRaised => $this->finalBillingFailure($project),

            CloseOutEvidence::InvoicesCollected => $this->uncollectedFailure($project),

            CloseOutEvidence::RetentionCollected => $this->releases->isFullyCollected($project)
                ? null
                : $this->retentionFailure($project),

            CloseOutEvidence::DemobilizationComplete => $this->demobilizationFailure($project),

            CloseOutEvidence::FinalAccountFiled => $this->finalAccounts->forProject($project) === null
                ? 'no final account has been filed for this project.'
                : null,

            CloseOutEvidence::ScorecardsFiled => $this->finalAccounts->unratedVendors($project)->isEmpty()
                ? null
                : sprintf(
                    'no scorecard is on file for %s.',
                    $this->finalAccounts->unratedVendors($project)->pluck('code')->implode(', '),
                ),
        };
    }

    private function turnoverFailure(Project $project): ?string
    {
        $pack = $this->turnovers->forProject($project);

        if ($pack === null) {
            return 'the project has no turnover pack.';
        }

        return $this->turnovers->isAccepted($pack)
            ? null
            : sprintf('turnover pack %s has not been accepted by the client.', $pack->number);
    }

    private function finalBillingFailure(Project $project): ?string
    {
        $raised = SalesInvoice::query()
            ->where('project_id', $project->getKey())
            ->get()
            ->contains(fn (SalesInvoice $invoice): bool => $this->finalBillings->isFinalBilling($invoice->billing()->sole()));

        return $raised ? null : 'no final billing has been invoiced on this project.';
    }

    private function uncollectedFailure(Project $project): ?string
    {
        $uncollected = SalesInvoice::query()
            ->where('project_id', $project->getKey())
            ->get()
            ->filter(fn (SalesInvoice $invoice): bool => bccomp($this->collections->outstandingFor($invoice), '0', 4) > 0);

        return $uncollected->isEmpty()
            ? null
            : sprintf(
                '%s still outstanding.',
                $uncollected->map(fn (SalesInvoice $invoice): string => sprintf(
                    '%s has %s',
                    $invoice->number,
                    $this->collections->outstandingFor($invoice),
                ))->implode('; '),
            );
    }

    /**
     * Only reached when retention is NOT fully collected, so there is always a
     * reason to give — the signature is `string`, not `?string`.
     */
    private function retentionFailure(Project $project): string
    {
        $claim = $this->releases->outstandingClaimFor($project);

        return $claim === null
            ? 'retention is still held by the client and has not been claimed.'
            : sprintf('retention claim %s is raised but uncollected.', $claim->number);
    }

    private function demobilizationFailure(Project $project): ?string
    {
        $demobilization = $this->demobilizations->forProject($project);

        if ($demobilization === null) {
            return 'the site has not been demobilized.';
        }

        return $this->demobilizations->isComplete($demobilization)
            ? null
            : sprintf(
                'demobilization %s is not complete: %s.',
                $demobilization->number,
                implode('; ', $this->demobilizations->outstandingFor($demobilization)),
            );
    }

    /**
     * @return array<int, array{key: string, label: string, panel: string, evidence: string}>
     */
    private function template(): array
    {
        /** @var array<int, array{key: string, label: string, panel: string, evidence: string}> $template */
        $template = config('closeout.checklist', []);

        if ($template === []) {
            throw new DomainException('No close-out checklist template is configured. See config/closeout.php.');
        }

        return $template;
    }
}
