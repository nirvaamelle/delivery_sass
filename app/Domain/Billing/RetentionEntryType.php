<?php

namespace App\Domain\Billing;

/**
 * A movement on the retention ledger.
 *
 * Typed rather than inferred from the sign of the amount, because "why is this
 * negative" is a question a report should not have to answer by arithmetic.
 */
enum RetentionEntryType: string
{
    case Withheld = 'withheld';
    case Released = 'released';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
