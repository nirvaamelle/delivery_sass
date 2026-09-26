<?php

namespace App\Domain\Billing;

/**
 * How much of an invoice has come in.
 *
 * `PartlyCollected` exists because partial payment is ordinary and the AR aging
 * sweep reads this state. Collapsing it into "collected" would drop a live
 * receivable off the list that chases it; collapsing it into "issued" would
 * chase money already received.
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';
    case PartlyCollected = 'partly_collected';
    case Collected = 'collected';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
