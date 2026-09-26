<?php

namespace App\Domain\Tax;

use App\Domain\Support\Money;
use InvalidArgumentException;

/**
 * Expanded withholding tax, computed without ever touching a float — F9.
 *
 * Every arithmetic operation goes through Money, which is where the scale AND
 * the rounding rule live. PLAN.md §4 says money is DECIMAL(18,4), never float,
 * and that rule is worth nothing if the number becomes a double on the way
 * through the calculation: a contract sum with 18 significant digits does not
 * survive a double, which holds about 15.
 *
 * The rounding used to live here privately, which is how it came to differ from
 * the quote lines in P1-04 — see Money's own docblock.
 *
 * PLACEHOLDER: Part D item 14 — the rates come from config/withholding.php and
 * the client is expected to overwrite them. The shape of the calculation is not
 * a placeholder.
 */
class WithholdingCalculator
{
    /**
     * The configured rate for a code.
     *
     * @throws UnknownWithholdingCode when the code is not in the rate table
     */
    public function rateFor(string $code): string
    {
        /** @var array<string, string> $rates */
        $rates = config('withholding.rates', []);

        if (! array_key_exists($code, $rates)) {
            throw new UnknownWithholdingCode(sprintf(
                'No withholding rate is configured for "%s". Configured codes: %s.',
                $code,
                $rates === [] ? 'none' : implode(', ', array_keys($rates))
            ));
        }

        return (string) $rates[$code];
    }

    /**
     * The amount to withhold from a payment.
     *
     * @throws InvalidArgumentException when the base amount is not a
     *                                  non-negative decimal string
     * @throws UnknownWithholdingCode when the code is not in the rate table
     */
    public function compute(string $baseAmount, string $code): string
    {
        $base = $this->assertAmount($baseAmount);
        $rate = $this->rateFor($code);

        return Money::multiply($base, $rate);
    }

    /**
     * What actually gets paid once the withholding is deducted.
     *
     * @throws InvalidArgumentException when the base amount is not a
     *                                  non-negative decimal string
     * @throws UnknownWithholdingCode when the code is not in the rate table
     */
    public function netOf(string $baseAmount, string $code): string
    {
        $base = $this->assertAmount($baseAmount);

        return Money::round(
            bcsub($base, $this->compute($base, $code), Money::WORKING_SCALE)
        );
    }

    /**
     * Reject anything that is not a plain non-negative decimal string.
     *
     * Thousands separators, currency symbols and negatives all arrive from
     * spreadsheet imports sooner or later. A negative base is refused rather
     * than guessed at: withholding on a credit note is its own decision, and
     * silently negating the amount would be the wrong one.
     */
    private function assertAmount(string $amount): string
    {
        $trimmed = trim($amount);

        if (preg_match('/^\d+(\.\d+)?$/', $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Base amount must be a non-negative decimal string, got "%s".',
                $amount
            ));
        }

        return $trimmed;
    }
}
