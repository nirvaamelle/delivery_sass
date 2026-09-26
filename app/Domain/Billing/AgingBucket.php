<?php

namespace App\Domain\Billing;

/**
 * The AR aging buckets — slide 6's weekly review.
 *
 * Thirty-day bands, because the escalation rule is a thirty-day rule: a report
 * whose columns do not line up with the rule that reads it makes the reader do
 * the arithmetic, and the reader is the person the escalation is chasing.
 */
enum AgingBucket: string
{
    case Current = 'current';
    case OneToThirty = '1_30';
    case ThirtyOneToSixty = '31_60';
    case SixtyOneToNinety = '61_90';
    case OverNinety = 'over_90';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Which bucket a given age falls in.
     */
    public static function forDays(int $days): self
    {
        return match (true) {
            $days <= 0 => self::Current,
            $days <= 30 => self::OneToThirty,
            $days <= 60 => self::ThirtyOneToSixty,
            $days <= 90 => self::SixtyOneToNinety,
            default => self::OverNinety,
        };
    }
}
