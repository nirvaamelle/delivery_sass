<?php

namespace App\Domain\Hris;

/**
 * Slide 7's step 1 — where a manpower request stands.
 */
enum ManpowerStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Filled = 'filled';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
