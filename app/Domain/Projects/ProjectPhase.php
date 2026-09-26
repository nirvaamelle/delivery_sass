<?php

namespace App\Domain\Projects;

use Filament\Support\Contracts\HasLabel;

/**
 * The four project phases from PLAN.md §1.
 *
 * F1: all four chains run phases 1→4. Every phase exists from the first
 * migration and no chain's tables are scoped to a subset of them — a missing
 * case here would silently scope a chain out of a phase it belongs in.
 */
enum ProjectPhase: string implements HasLabel
{
    case ProjectAcquisition = 'project_acquisition';
    case PreConstruction = 'pre_construction';
    case Construction = 'construction';
    case PostConstruction = 'post_construction';

    /**
     * Account vocabulary over construction storage — spec §2, D3 wrinkle.
     *
     * The stored values stay as they are. Migrating them would rewrite a column
     * ProjectService transitions on, to buy nothing a label does not buy.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::ProjectAcquisition => 'Onboarding',
            self::PreConstruction => 'Go-Live',
            self::Construction => 'Operating',
            self::PostConstruction => 'Exit',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
