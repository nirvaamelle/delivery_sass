<?php

namespace App\Domain\Closeout;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectStatus;
use App\Models\Project;
use App\Models\SubstantialCompletion;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Slide 9 step 1 — substantial completion, owned by the Project Manager.
 *
 * It is the hinge of the whole phase. Everything downstream dates from it: the
 * punchlist is issued against it, the 95% pre-final milestone is triggered by
 * it, turnover follows it, and retention is released a defects liability period
 * after the works are accepted. A certificate written carelessly is not one bad
 * row — it is a wrong date on six documents.
 *
 * Hence three refusals, all of them about a certificate that would be a fiction
 * rather than a record.
 */
class SubstantialCompletionService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
    ) {}

    /**
     * Certify that the works are usable for their intended purpose.
     *
     * @throws DomainException when the project never reached construction, is
     *                         already closed, or the date has not happened yet
     */
    public function certify(
        Project $project,
        CarbonInterface $certifiedOn,
        User $by,
        ?string $clientRepresentative = null,
        string $remarks = '',
    ): SubstantialCompletion {
        if (! in_array($project->phase, [ProjectPhase::Construction, ProjectPhase::PostConstruction], true)) {
            // Certifying the completion of works that never started. The phase
            // is not decoration — PLAN.md §1 runs every chain through all four.
            throw new DomainException(sprintf(
                'Project %s has not reached construction (%s). There is nothing yet to be substantially complete.',
                $project->code,
                $project->phase->value,
            ));
        }

        if ($project->status === ProjectStatus::Closed) {
            throw new DomainException(sprintf(
                'Project %s is already closed. A certificate issued after close-out reopens a period nobody is watching.',
                $project->code,
            ));
        }

        if ($certifiedOn->isAfter(now())) {
            // A certificate dated forward is a promise, and everything
            // downstream — the punchlist, the DLP, the retention release —
            // would date from it.
            throw new DomainException(sprintf(
                'Substantial completion on %s has not happened yet. A certificate cannot be dated forward.',
                $certifiedOn->toDateString(),
            ));
        }

        if (SubstantialCompletion::query()->where('project_id', $project->getKey())->exists()) {
            throw new DomainException(sprintf(
                'Project %s is already certified substantially complete. A second certificate gives the defects liability period two start dates.',
                $project->code,
            ));
        }

        return DB::transaction(function () use ($project, $certifiedOn, $by, $clientRepresentative, $remarks): SubstantialCompletion {
            $certificate = SubstantialCompletion::query()->create([
                'project_id' => $project->getKey(),
                'number' => $this->numbering->next('SCC'),
                'certified_on' => $certifiedOn,
                'certified_by_user_id' => $by->getKey(),
                'client_representative' => $clientRepresentative,
                'remarks' => $remarks === '' ? null : $remarks,
            ]);

            // The certificate IS the move into post-construction. Leaving the
            // phase to be updated by hand afterwards would mean a project that
            // is certified complete and still reads as under construction on
            // every screen that asks.
            $project->update(['phase' => ProjectPhase::PostConstruction]);

            return $certificate;
        });
    }

    public function forProject(Project $project): ?SubstantialCompletion
    {
        return SubstantialCompletion::query()
            ->where('project_id', $project->getKey())
            ->first();
    }
}
