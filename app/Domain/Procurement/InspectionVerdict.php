<?php

namespace App\Domain\Procurement;

/**
 * The outcome of inspecting a delivery.
 *
 * `PartiallyRejected` is its own verdict rather than a shade of either
 * neighbour, because it is the common case and the one that needs handling:
 * some material goes to stock and some goes back on the truck, and a single
 * pass/fail forces somebody to choose which lie to tell.
 */
enum InspectionVerdict: string
{
    case Passed = 'passed';
    case PartiallyRejected = 'partially_rejected';
    case Rejected = 'rejected';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
