<?php

namespace App\Domain\Hris;

/**
 * What the site has said about a day.
 *
 * **`Held` is the state PHASE-PLAN.md says Phase 3 exists to settle**, and it is
 * the one an obvious implementation leaves out. Slide 7's rule is "no timelog,
 * no pay — unvalidated days are held and paid in the next cutoff once the site
 * certifies them", and the tempting reading is that an uncertified day is simply
 * not paid. But the person worked it. Dropping the day leaves them short a
 * fortnight with nothing recording why, and the correction arrives as somebody
 * typing an adjustment line with no evidence behind it.
 *
 * `Rejected` is a third outcome and deliberately not a shade of `Held`: a day
 * that never happened must not carry forward waiting to be certified.
 */
enum DtrStatus: string
{
    case Unvalidated = 'unvalidated';
    case Validated = 'validated';
    case Held = 'held';
    case Rejected = 'rejected';
    case Paid = 'paid';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * May this day be paid in a run?
     *
     * Validated only. A held day becomes payable by being validated late, not by
     * being held — "no timelog, no pay" survives the mechanic.
     */
    public function isPayable(): bool
    {
        return $this === self::Validated;
    }
}
