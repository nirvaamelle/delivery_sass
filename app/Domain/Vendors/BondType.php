<?php

namespace App\Domain\Vendors;

/**
 * The bonds a vendor may carry — F15.
 *
 * Each covers a different exposure and each expires on its own clock, which is
 * why they are typed rather than counted. A surety bond is not a performance
 * bond, and accepting either in place of the other defeats the point of having
 * asked for a specific one.
 */
enum BondType: string
{
    /** Guarantees the bid itself. */
    case Surety = 'surety';

    /** Guarantees the work is completed as contracted. */
    case Performance = 'performance';

    /** Covers defects after turnover, through the DLP. */
    case Warranty = 'warranty';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
