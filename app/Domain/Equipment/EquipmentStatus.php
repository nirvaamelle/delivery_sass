<?php

namespace App\Domain\Equipment;

/**
 * Where a machine is in its life.
 *
 * `Returned` is separate from `Disposed` because slide 9's demobilization asks
 * for equipment to be "returned and logged" — a rented machine going back to its
 * owner has left the company without being sold, and collapsing the two would
 * make the fleet's disposal history unreadable.
 */
enum EquipmentStatus: string
{
    case Available = 'available';
    case Deployed = 'deployed';
    case UnderRepair = 'under_repair';
    case Returned = 'returned';
    case Disposed = 'disposed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Has the machine left the fleet for good?
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Returned, self::Disposed], true);
    }
}
