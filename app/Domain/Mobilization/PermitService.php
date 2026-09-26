<?php

namespace App\Domain\Mobilization;

use App\Models\Permit;
use App\Models\Project;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Permits — the second half of F2's "site setup and permits secured".
 *
 * This is the third expiry clock in the system, after vendor accreditations and
 * bonds, and it follows the same two rules both of those established:
 *
 *   - **validity is derived on read, never stored.** A cached `is_valid` flag is
 *     only as true as the last scheduled job that ran, and the day that job
 *     fails quietly is the day a site mobilizes on a lapsed permit;
 *   - **renewals append.** The question asked after an incident is whether the
 *     site was permitted ON THE DAY, and overwriting keeps only the answer for
 *     today.
 */
class PermitService
{
    /**
     * Register a permit against a project.
     *
     * @throws DomainException when the permit expires before it was issued
     */
    public function register(
        Project $project,
        PermitType $type,
        string $number,
        string $issuingAuthority,
        CarbonInterface $issuedOn,
        CarbonInterface $expiresOn,
        ?string $remarks = null,
    ): Permit {
        if ($expiresOn->lt($issuedOn)) {
            // Refused rather than stored: such a row covers nothing, and it
            // would read as merely lapsed rather than as the data-entry error
            // it actually is.
            throw new DomainException(sprintf(
                'Permit %s expires %s but was issued %s. A permit that expired before it existed covers nothing.',
                $number,
                $expiresOn->toDateString(),
                $issuedOn->toDateString(),
            ));
        }

        return Permit::query()->create([
            'project_id' => $project->getKey(),
            'type' => $type,
            'number' => $number,
            'issuing_authority' => $issuingAuthority,
            'issued_on' => $issuedOn,
            'expires_on' => $expiresOn,
            'remarks' => $remarks,
        ]);
    }

    /**
     * Is this permit in force on a given day?
     *
     * Inclusive at both ends. Somebody will stand on the last day of a permit,
     * and being wrong in either direction is a site either idled for no reason
     * or working uncovered.
     */
    public function isValid(Permit $permit, ?CarbonInterface $asOf = null): bool
    {
        $asOf = $asOf ?? now();

        return ! $asOf->startOfDay()->lt($permit->issued_on)
            && ! $asOf->startOfDay()->gt($permit->expires_on);
    }

    /**
     * The permit of this type currently in force for a project, if any.
     *
     * Latest expiry first, so a renewal registered alongside the permit it
     * replaces is the one that answers.
     */
    public function validFor(Project $project, PermitType $type, ?CarbonInterface $asOf = null): ?Permit
    {
        $asOf = ($asOf ?? now())->startOfDay();

        return Permit::query()
            ->where('project_id', $project->getKey())
            ->where('type', $type)
            ->whereDate('issued_on', '<=', $asOf)
            ->whereDate('expires_on', '>=', $asOf)
            ->orderByDesc('expires_on')
            ->first();
    }

    /**
     * Does the project hold any permit in force at all?
     *
     * Deliberately separate from `validFor()`. The mobilization checklist asks
     * whether the site is permitted; which permits a particular site needs is a
     * question the client has not answered, and inventing a required set here
     * would enforce a rule nobody agreed to.
     *
     * PLACEHOLDER: the required permit set per project type is not in the deck.
     */
    public function hasAnyValid(Project $project, ?CarbonInterface $asOf = null): bool
    {
        $asOf = ($asOf ?? now())->startOfDay();

        return Permit::query()
            ->where('project_id', $project->getKey())
            ->whereDate('issued_on', '<=', $asOf)
            ->whereDate('expires_on', '>=', $asOf)
            ->exists();
    }

    /**
     * Permits on this project that have run out.
     *
     * The list a site manager needs before anybody asks for it.
     *
     * @return Collection<int, Permit>
     */
    public function expiredFor(Project $project, ?CarbonInterface $asOf = null): Collection
    {
        $asOf = ($asOf ?? now())->startOfDay();

        return Permit::query()
            ->where('project_id', $project->getKey())
            ->whereDate('expires_on', '<', $asOf)
            ->orderByDesc('expires_on')
            ->get();
    }
}
