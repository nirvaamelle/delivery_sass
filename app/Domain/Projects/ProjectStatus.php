<?php

namespace App\Domain\Projects;

/**
 * Project lifecycle status, distinct from phase.
 *
 * PLAN.md §5: "A project stays open in the books until retention is collected."
 * A project therefore reaches PostConstruction long before it reaches Closed,
 * which is why status is its own column and not derived from the phase.
 */
enum ProjectStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
