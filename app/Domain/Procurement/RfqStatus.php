<?php

namespace App\Domain\Procurement;

/**
 * RFQ lifecycle.
 *
 * `Draft` is where the recipient list is assembled and where the three-quote
 * minimum has not yet been asserted. `Issued` means it went out, and the
 * minimum held at that moment.
 */
enum RfqStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
