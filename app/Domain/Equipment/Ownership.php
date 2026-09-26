<?php

namespace App\Domain\Equipment;

/**
 * How the company holds a machine — and therefore how its cost reaches a
 * project.
 *
 * The distinction is not administrative. An owned machine is capitalised and
 * depreciated over its life; a rented one is an expense as it is incurred, and
 * depreciating it would capitalise a cost already paid in full and count it
 * twice. `DepreciationService` refuses anything but `Owned` for that reason.
 *
 * PLACEHOLDER: Part D item 15 — the client has not said whether the fleet is
 * owned, rented or both. All three exist here so the answer is data.
 */
enum Ownership: string
{
    case Owned = 'owned';
    case Rented = 'rented';
    case Leased = 'leased';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
