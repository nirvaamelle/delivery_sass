<?php

namespace App\Domain\Support;

use InvalidArgumentException;

/**
 * The one place money arithmetic decides how to round.
 *
 * PLAN.md §4 fixes the scale — DECIMAL(18,4), never float, never integer cents
 * — but the scale alone does not settle what happens to the fifth decimal, and
 * that is where two parts of a system quietly disagree.
 *
 * This exists because they did. `WithholdingCalculator` rounded half up;
 * `QuoteService` multiplied with `bcmul` at scale 4, which **truncates**. A
 * quote line of 12.5 × 1,499.9999 is exactly 18,749.99875 — truncation calls it
 * …87, rounding calls it …88. One centavo, on one line. But the truncation is
 * systematic and always downward, and the two rules meet at the three-way
 * match, where a discrepancy nobody can source is worse than either answer.
 *
 * Half up, because that is what the rest of the accounting the deck describes
 * uses, and because banker's rounding would surprise everyone reading a
 * printed abstract of canvass.
 */
class Money
{
    /**
     * The DECIMAL(18,4) contract from PLAN.md §4.
     */
    public const SCALE = 4;

    /**
     * Intermediate scale for multiplication and division.
     *
     * Carried well past the money scale so the rounding decision is made once,
     * at the end, on a full-precision product rather than on one that has
     * already lost digits.
     */
    public const WORKING_SCALE = 12;

    /**
     * Round a decimal string half up to the money scale.
     *
     * bcmath truncates rather than rounds, so half is added at the first
     * discarded place and the result truncated. Negative values are handled
     * explicitly: adding half to a negative would round toward zero, which is
     * not half-up, and reversing entries in the ledger are negative.
     */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        self::assertDecimal($value);

        $half = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with($value, '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }

    /**
     * Multiply two decimal strings, rounded to the money scale.
     */
    public static function multiply(string $a, string $b): string
    {
        self::assertDecimal($a);
        self::assertDecimal($b);

        return self::round(bcmul($a, $b, self::WORKING_SCALE));
    }

    /**
     * Add decimal strings exactly.
     */
    public static function sum(string ...$values): string
    {
        $total = '0';

        foreach ($values as $value) {
            self::assertDecimal($value);
            $total = bcadd($total, $value, self::SCALE);
        }

        return bcadd($total, '0', self::SCALE);
    }

    /**
     * Is $a greater than $b?
     */
    public static function greaterThan(string $a, string $b): bool
    {
        return bccomp($a, $b, self::SCALE) > 0;
    }

    public static function isZero(string $value): bool
    {
        return bccomp($value, '0', self::SCALE) === 0;
    }

    /**
     * @throws InvalidArgumentException when the value is not a decimal string
     */
    private static function assertDecimal(string $value): void
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', trim($value)) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Money must be a decimal string, got "%s". A float here is exactly what PLAN.md §4 forbids.',
                $value,
            ));
        }
    }
}
