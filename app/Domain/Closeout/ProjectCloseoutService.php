<?php

namespace App\Domain\Closeout;

use App\Domain\Billing\CollectionService;
use App\Domain\Billing\RetentionService;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Support\Money;
use App\Models\Project;
use App\Models\SalesInvoice;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closing the project in the books — slide 9 step 6.
 *
 * The closing rule, and the phase's exit gate: **"a project stays open in the
 * books until retention is collected."** Collected, not released. That word is
 * the reason P5-07 splits the claim from the money, and this service is where
 * the distinction is enforced.
 *
 * Retention is not the only thing that keeps a project open, and the other two
 * follow from the same idea — a project is closed when nothing is owed to it
 * and nothing of its is still out. So an uncollected invoice keeps it open, and
 * so does a demobilization nobody finished: plant on a site the company has
 * stopped watching is plant that goes missing.
 *
 * From P5-08 the checklist is the fourth, and it is not paperwork beside the
 * close — it IS the close. Slide 9: "close-out is a checklist with named
 * clearers per line." A project closed over an unsigned line has a close-out
 * report naming nobody for it.
 *
 * Every reason is reported at once. A closing accountant told one blocker at a
 * time makes one pass a week, and the month-end they are closing for has
 * already gone.
 */
class ProjectCloseoutService
{
    public function __construct(
        private readonly RetentionService $retention,
        private readonly RetentionReleaseService $releases,
        private readonly CollectionService $collections,
        private readonly DemobilizationService $demobilizations,
        private readonly CloseOutChecklistService $checklists,
    ) {}

    /**
     * Everything keeping the project open.
     *
     * @return array<int, string>
     */
    public function outstandingFor(Project $project): array
    {
        $outstanding = [];

        if (! $this->releases->isFullyCollected($project)) {
            $claim = $this->releases->outstandingClaimFor($project);

            $outstanding[] = $claim === null
                ? sprintf(
                    'Retention of %s is still held by the client and has not been claimed.',
                    $this->retention->balanceFor($project),
                )
                : sprintf(
                    'Retention claim %s for %s is raised but uncollected. A project closed on a claim is closed on a promise.',
                    $claim->number,
                    $claim->amount,
                );
        }

        foreach ($this->uncollectedInvoices($project) as $invoice) {
            $outstanding[] = sprintf(
                'Invoice %s has %s outstanding.',
                $invoice->number,
                $this->collections->outstandingFor($invoice),
            );
        }

        $checklist = $this->checklists->forProject($project);

        if ($checklist === null) {
            // Absence is not permission. A project with nothing else
            // outstanding is exactly the one most likely to walk past this.
            $outstanding[] = 'The project has no close-out checklist, so nothing has been signed off and the close-out report would name nobody.';
        } elseif (! $this->checklists->isComplete($checklist)) {
            $outstanding[] = sprintf(
                'The close-out checklist %s is not cleared line by line: %s.',
                $checklist->number,
                implode('; ', $this->checklists->outstandingFor($checklist)),
            );
        }

        $demobilization = $this->demobilizations->forProject($project);

        if ($demobilization === null) {
            $outstanding[] = 'The site has not been demobilized. Plant on a site nobody is watching is plant that goes missing.';
        } elseif (! $this->demobilizations->isComplete($demobilization)) {
            $outstanding[] = sprintf(
                'Demobilization %s is not complete: %s.',
                $demobilization->number,
                implode('; ', $this->demobilizations->outstandingFor($demobilization)),
            );
        }

        return $outstanding;
    }

    public function canClose(Project $project): bool
    {
        return $this->outstandingFor($project) === [];
    }

    /**
     * Close the project.
     *
     * @throws DomainException when it is already closed, or anything is still
     *                         outstanding
     */
    public function close(Project $project, User $by, ?string $remarks = null): Project
    {
        if ($project->status === ProjectStatus::Closed) {
            throw new DomainException(sprintf('Project %s is already closed.', $project->code));
        }

        $outstanding = $this->outstandingFor($project);

        if ($outstanding !== []) {
            throw new DomainException(sprintf(
                'Project %s cannot be closed. %s',
                $project->code,
                implode(' ', $outstanding),
            ));
        }

        return DB::transaction(function () use ($project, $by, $remarks): Project {
            $project->update(['status' => ProjectStatus::Closed]);

            // Recorded on the activity log rather than in columns on the
            // project. Who closed it and when is one line of a close-out
            // report, and P5-08 is the document that assembles it — a pair of
            // columns here would be the second place that fact lives.
            activity()
                ->performedOn($project)
                ->causedBy($by)
                ->withProperties(['remarks' => $remarks])
                ->log('project-closed');

            return $project->refresh();
        });
    }

    public function isClosed(Project $project): bool
    {
        return $project->status === ProjectStatus::Closed;
    }

    /**
     * Invoices on the project with money still to come in.
     *
     * @return Collection<int, SalesInvoice>
     */
    public function uncollectedInvoices(Project $project): Collection
    {
        return SalesInvoice::query()
            ->where('project_id', $project->getKey())
            ->orderBy('issued_on')
            ->get()
            ->filter(fn (SalesInvoice $invoice): bool => ! Money::isZero($this->collections->outstandingFor($invoice)))
            ->values();
    }
}
