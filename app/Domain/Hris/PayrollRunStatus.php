<?php

namespace App\Domain\Hris;

/**
 * Slide 7's steps 6 to 8: processing, review and approval, disbursement.
 *
 * `Computed` and `Approved` are separate because F10 lives between them: a
 * computed register whose variance against the last cutoff is unexplained must
 * not become an approved one.
 */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Computed = 'computed';
    case Approved = 'approved';
    case Released = 'released';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
