<?php

namespace App\Domain\Opex;

/**
 * Where a cash advance stands against slide 8's day-26 rule.
 *
 * `PartlyLiquidated` is a real state and F17's day-26 job depends on it: an
 * advance with ₱1,600 left is charged for ₱1,600, not for nothing and not for
 * the whole of it. A two-state model — settled or not — would force the job to
 * choose between under-charging and over-charging, every month.
 *
 * `ChargedToPayroll` is terminal here and the start of something else: the
 * balance has left OPEX and become a payroll deduction, and the advance must not
 * then be liquidated a second time with receipts that arrive late.
 */
enum CashAdvanceStatus: string
{
    case Released = 'released';
    case PartlyLiquidated = 'partly_liquidated';
    case Liquidated = 'liquidated';
    case ChargedToPayroll = 'charged_to_payroll';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Is there still money to account for?
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Released, self::PartlyLiquidated], true);
    }
}
