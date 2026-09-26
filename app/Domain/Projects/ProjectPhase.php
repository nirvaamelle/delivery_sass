<?php

namespace App\Domain\Projects;

/**
 * The four project phases from PLAN.md §1.
 *
 * F1: all four chains run phases 1→4. Every phase exists from the first
 * migration and no chain's tables are scoped to a subset of them — a missing
 * case here would silently scope a chain out of a phase it belongs in.
 */
enum ProjectPhase: string
{
    case ProjectAcquisition = 'project_acquisition';
    case PreConstruction = 'pre_construction';
    case Construction = 'construction';
    case PostConstruction = 'post_construction';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
