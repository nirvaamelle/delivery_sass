<?php

namespace App\Domain\Gates\Preconditions;

use App\Domain\Contracts\ContractStatus;
use App\Domain\Gates\Precondition;
use App\Models\Project;

/**
 * F14, first half: no spending against a project whose contract is not signed.
 *
 * Without this the company commits to costs against revenue it has not
 * contractually secured — and the deck's own failure mode is that the paperwork
 * exists, so the work looks authorised.
 */
class ProjectHasSignedContract implements Precondition
{
    public function name(): string
    {
        return 'project-has-signed-contract';
    }

    public function passes(object $subject): bool
    {
        if (! $subject instanceof Project) {
            return false;
        }

        // Scoped to this project's own contracts. An unscoped check would let
        // the first signed contract in the database unlock every project.
        return $subject->contracts()
            ->where('status', ContractStatus::Signed)
            ->exists();
    }

    public function failureMessage(object $subject): string
    {
        return 'The project has no signed contract.';
    }
}
