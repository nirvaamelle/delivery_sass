<?php

namespace App\Domain\Vendors;

/**
 * The outcome of a vendor validation visit — F15.
 *
 * Somebody went to the address on the accreditation form. `Passed` means what
 * was declared was there; `Failed` means it was not. `PassedWithFindings` exists
 * because the middle case is the common one and collapsing it into either
 * neighbour loses the reason anyone bothered to visit.
 */
enum ValidationVerdict: string
{
    case Passed = 'passed';
    case PassedWithFindings = 'passed_with_findings';
    case Failed = 'failed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
