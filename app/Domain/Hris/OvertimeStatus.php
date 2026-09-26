<?php

namespace App\Domain\Hris;

/**
 * Where an overtime authority stands.
 *
 * `Requested` pays nothing. "Approved in writing" is about the approval, not the
 * ask, and an authority that paid on request would make the site engineer's
 * signature a formality applied after the payroll run.
 */
enum OvertimeStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
