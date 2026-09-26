<?php

namespace App\Domain\Billing;

/**
 * Whether the client has agreed to what was measured.
 *
 * `Measured` is where every accomplishment starts and `Verified` is a
 * consequence of two signatures, never something set directly. Slide 6 makes the
 * client's signature the condition an invoice rests on, and a status anybody can
 * write would make that signature a formality applied after the decision.
 */
enum AccomplishmentStatus: string
{
    case Measured = 'measured';
    case Verified = 'verified';
    case Superseded = 'superseded';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
