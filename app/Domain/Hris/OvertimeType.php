<?php

namespace App\Domain\Hris;

/**
 * The two premiums slide 7 says must be approved in writing.
 *
 * Separate types rather than one "extra hours" authority, because they answer
 * different questions: overtime is about how LONG somebody worked, night
 * differential is about WHEN. A 14:00–23:00 shift is inside eight hours and still
 * owes night differential for the last hour, and an authority that covered both
 * with one figure could not express that.
 */
enum OvertimeType: string
{
    case Overtime = 'overtime';
    case NightDifferential = 'night_differential';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
