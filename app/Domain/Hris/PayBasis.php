<?php

namespace App\Domain\Hris;

/**
 * What a rate is a rate OF.
 *
 * Site labour on the deck's projects is daily-rated and the office is monthly,
 * and the two compute differently from the same DTR: a daily worker paid for
 * fifteen days worked is not a monthly worker paid half a month. Storing the
 * basis alongside the figure is what keeps that decision with the rate rather
 * than in whichever payroll routine happens to read it.
 */
enum PayBasis: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';
    case Hourly = 'hourly';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
