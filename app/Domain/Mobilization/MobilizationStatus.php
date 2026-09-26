<?php

namespace App\Domain\Mobilization;

/**
 * Where a mobilization is.
 *
 * `InProgress` is the state a mobilization is BORN in, not one it graduates to.
 * Slide 3's step 5 begins the moment the gate opens — the crew is travelling,
 * the compound is being set up — and a draft state would suggest mobilization is
 * something planned rather than something happening.
 */
enum MobilizationStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
