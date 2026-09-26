<?php

namespace App\Domain\Projects;

/**
 * What somebody does on a particular project.
 *
 * Distinct from the spatie role that decides authority. A person can be a
 * project manager on the Cebu job and a site engineer on the Davao one, and the
 * approval tier they sign at is neither of those — it is the role on their
 * account. Conflating the two would make an authority matrix that changes by
 * which project a document belongs to.
 */
enum ProjectRole: string
{
    case ProjectManager = 'project_manager';
    case SiteEngineer = 'site_engineer';
    case Foreman = 'foreman';
    case QuantitySurveyor = 'quantity_surveyor';
    case Timekeeper = 'timekeeper';
    case Storekeeper = 'storekeeper';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
