<?php

namespace App\Domain\Billing;

use App\Domain\Documents\DocumentLinker;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Models\Accomplishment;
use App\Models\JointSurvey;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Accomplishments and the joint survey that verifies them — slide 6's small
 * print, and the rule the whole billing chain rests on: "every submission needs
 * a joint accomplishment survey signed by the client representative before an
 * invoice can be raised."
 *
 * **Verified is a consequence, not a status somebody sets.** An accomplishment
 * becomes verified when two signatures exist on its survey, and by no other
 * route. If `status` were writable, the client's signature would be a formality
 * applied to a decision already taken — which is precisely the failure the deck
 * describes elsewhere as "the paperwork exists".
 *
 * **Both signatures, and the client's is the one that binds.** A survey the
 * contractor signed alone is a measurement, not an agreement; a measurement the
 * client never agreed to is what a returned billing is made of.
 *
 * The subtler rule is about decreases. Progress normally rises, but a returned
 * billing is re-measured and the figure can legitimately come down. Refusing a
 * decrease outright would make the returned branch unusable; allowing it
 * silently would let a figure drop with nothing recording why. So it is allowed
 * and it requires a reason.
 */
class AccomplishmentService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly DocumentLinker $links,
    ) {}

    /**
     * Measure progress for a period.
     *
     * @throws DomainException when the period is inverted, the percentage is out
     *                         of bounds, or it decreases with no reason given
     */
    public function record(
        Project $project,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        string $percentageComplete,
        ?User $measuredBy = null,
        ?string $remarks = null,
    ): Accomplishment {
        if ($periodEnd->lt($periodStart)) {
            throw new DomainException(sprintf(
                'Accomplishment period ends %s but starts %s.',
                $periodEnd->toDateString(),
                $periodStart->toDateString(),
            ));
        }

        if (bccomp($percentageComplete, '0.00', 2) < 0 || bccomp($percentageComplete, '100.00', 2) > 0) {
            // 110% complete is not ahead of schedule, it is a measurement error
            // — and it would bill the client for more than the contract.
            throw new DomainException(sprintf(
                'Accomplishment of %s%% is outside 0–100. A figure above 100 bills more than the contract.',
                $percentageComplete,
            ));
        }

        $previous = $this->latestFor($project);
        $previousPercentage = $previous === null ? '0.00' : (string) $previous->percentage_complete;

        if (bccomp($percentageComplete, $previousPercentage, 2) < 0 && trim((string) $remarks) === '') {
            throw new DomainException(sprintf(
                'Accomplishment falls from %s%% to %s%% with no reason recorded. A re-measurement is legitimate; a figure that drops silently is the one nobody can account for at close-out.',
                $previousPercentage,
                $percentageComplete,
            ));
        }

        return DB::transaction(function () use ($project, $periodStart, $periodEnd, $percentageComplete, $previousPercentage, $previous, $measuredBy, $remarks): Accomplishment {
            $accomplishment = Accomplishment::query()->create([
                'project_id' => $project->getKey(),
                'number' => $this->numbering->next('ACC'),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'percentage_complete' => $percentageComplete,
                'previous_percentage' => $previousPercentage,
                'status' => AccomplishmentStatus::Measured,
                'measured_by_user_id' => $measuredBy?->getKey(),
                'remarks' => $remarks,
            ]);

            if ($previous !== null) {
                $this->links->link($previous, $accomplishment);
            }

            return $accomplishment->refresh();
        });
    }

    /**
     * Open the joint survey for a measurement.
     *
     * The representatives are named now, before anyone signs. Accepting any name
     * at signing time would make the field decorative and the signature
     * unattributable — and this signature is what an invoice rests on.
     */
    public function openSurvey(
        Accomplishment $accomplishment,
        CarbonInterface $surveyedOn,
        string $clientRepresentative,
        string $contractorRepresentative,
        ?string $remarks = null,
    ): JointSurvey {
        foreach (['client' => $clientRepresentative, 'contractor' => $contractorRepresentative] as $side => $name) {
            if (trim($name) === '') {
                throw new DomainException(sprintf('The %s representative must be named before the survey opens.', $side));
            }
        }

        return DB::transaction(function () use ($accomplishment, $surveyedOn, $clientRepresentative, $contractorRepresentative, $remarks): JointSurvey {
            $survey = JointSurvey::query()->create([
                'accomplishment_id' => $accomplishment->getKey(),
                'number' => $this->numbering->next('JS'),
                'surveyed_on' => $surveyedOn,
                'client_representative' => $clientRepresentative,
                'contractor_representative' => $contractorRepresentative,
                'remarks' => $remarks,
            ]);

            $this->links->link($accomplishment, $survey);

            return $survey->refresh();
        });
    }

    public function signAsContractor(JointSurvey $survey, ?User $by = null): JointSurvey
    {
        if ($survey->contractor_signed_at !== null) {
            throw new DomainException(sprintf('Survey %s was already signed for the contractor.', $survey->number));
        }

        $survey->update([
            'contractor_signed_at' => now(),
            'contractor_signed_by_user_id' => $by?->getKey(),
        ]);

        return $this->settle($survey->refresh());
    }

    /**
     * The signature that binds.
     *
     * @throws DomainException when signed by anybody but the named representative
     */
    public function signAsClient(JointSurvey $survey, string $signatory): JointSurvey
    {
        if ($survey->client_signed_at !== null) {
            throw new DomainException(sprintf('Survey %s was already signed for the client.', $survey->number));
        }

        if (trim($signatory) !== trim($survey->client_representative)) {
            throw new DomainException(sprintf(
                'Survey %s names %s as the client representative; "%s" signed. A signature that does not match the named representative is one nobody can stand behind.',
                $survey->number,
                $survey->client_representative,
                $signatory,
            ));
        }

        $survey->update([
            'client_signed_at' => now(),
            'client_signed_by' => $signatory,
        ]);

        return $this->settle($survey->refresh());
    }

    /**
     * Has this measurement been agreed by both sides?
     */
    public function isVerified(Accomplishment $accomplishment): bool
    {
        return $accomplishment->status === AccomplishmentStatus::Verified;
    }

    /**
     * The latest VERIFIED percentage for a project — the number the billing gate
     * reads.
     *
     * Unverified measurements are invisible to it, which is the whole content of
     * "no billing without a verified statement of accomplishment". A project with
     * nothing signed has verified zero: absence is not permission.
     */
    public function verifiedPercentageFor(Project $project): string
    {
        $latest = Accomplishment::query()
            ->where('project_id', $project->getKey())
            ->where('status', AccomplishmentStatus::Verified)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();

        return $latest === null ? '0.00' : (string) $latest->percentage_complete;
    }

    /**
     * What this period moved — the figure a progress billing bills.
     */
    public function periodMovement(Accomplishment $accomplishment): string
    {
        return bcsub(
            (string) $accomplishment->percentage_complete,
            (string) $accomplishment->previous_percentage,
            2,
        );
    }

    /**
     * The most recent measurement for a project, verified or not.
     */
    public function latestFor(Project $project): ?Accomplishment
    {
        return Accomplishment::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Promote the accomplishment once, when the second signature lands.
     */
    private function settle(JointSurvey $survey): JointSurvey
    {
        if (! $survey->isFullySigned()) {
            return $survey;
        }

        $accomplishment = $survey->accomplishment()->sole();

        if ($accomplishment->status === AccomplishmentStatus::Measured) {
            $accomplishment->update(['status' => AccomplishmentStatus::Verified]);
        }

        return $survey;
    }
}
