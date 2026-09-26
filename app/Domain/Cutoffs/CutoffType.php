<?php

namespace App\Domain\Cutoffs;

/**
 * The three cutoff cadences — F3.
 *
 * A single calendar cannot answer "is this period still open", because the
 * chains close on different rhythms:
 *
 *   - Billing  · monthly, closing after the month it bills for
 *   - Payroll  · semi-monthly (1–15, 16–EOM), per PLAN.md §4
 *   - Opex     · monthly, but cutting off on day 26, before the month ends
 *
 * The type is therefore part of the lookup, not a property of the period. The
 * Posting service takes it as an argument so "nothing books after cutoff"
 * resolves against the right calendar.
 */
enum CutoffType: string
{
    case Billing = 'billing';
    case Payroll = 'payroll';
    case Opex = 'opex';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
