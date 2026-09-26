<?php

namespace App\Domain\Procurement;

/**
 * Why stock moved.
 *
 * Every change to a balance is one of these, and each carries its sign in the
 * movement's own quantity. Typing the movement is what lets the card answer
 * "where did the other forty bags go" rather than merely "there are forty
 * fewer".
 */
enum StockMovementType: string
{
    /** Accepted goods entering the warehouse. */
    case Receipt = 'receipt';

    /** Material issued to the works, against a cost code. */
    case Issuance = 'issuance';

    /** The difference a physical count found. */
    case Adjustment = 'adjustment';

    /** Rejected material going back to the vendor. */
    case Return = 'return';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
