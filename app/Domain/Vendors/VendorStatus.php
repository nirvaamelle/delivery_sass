<?php

namespace App\Domain\Vendors;

/**
 * Vendor standing — F11.
 *
 * An enum, not a boolean. "Suspended until cleared by management" is a sentence
 * about accountability, and a boolean records none of it: not who suspended,
 * not why, not who is allowed to lift it. The vendor whose suspension was
 * quietly reversed is precisely the one somebody will later want to ask about.
 *
 * `Removed` is terminal. PLAN.md §5: falsified documents mean immediate
 * removal, and a route back would make that sentence meaningless.
 */
enum VendorStatus: string
{
    case Pending = 'pending';
    case Accredited = 'accredited';
    case Suspended = 'suspended';
    case Removed = 'removed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
