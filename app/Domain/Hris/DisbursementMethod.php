<?php

namespace App\Domain\Hris;

/**
 * Slide 7's step 8: "disbursement by bank upload OR cash payout with
 * acknowledgment."
 *
 * The two are separate cases rather than a flag on one payment, because they
 * prove payment in completely different ways. A bank upload is evidenced by the
 * file that went to the bank and the reference that came back, and nobody signs
 * anything. A cash payout is evidenced by a signature and by nothing else —
 * which is why the deck attaches "with acknowledgment" to this half alone.
 */
enum DisbursementMethod: string
{
    case BankUpload = 'bank_upload';
    case CashPayout = 'cash_payout';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Does money only count as released once somebody signs for it?
     */
    public function requiresAcknowledgment(): bool
    {
        return $this === self::CashPayout;
    }
}
