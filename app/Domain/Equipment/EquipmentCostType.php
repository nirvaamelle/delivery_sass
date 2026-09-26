<?php

namespace App\Domain\Equipment;

/**
 * The equipment costs PHASE-PLAN.md names as monthly consolidation inputs.
 *
 * Fuel and repair come from the finding directly. Mobilization is here because
 * it is the one cost that belongs entirely to a single assignment — moving a
 * machine to site is incurred for that project and no other — and lumping it
 * into repair would lose that.
 */
enum EquipmentCostType: string
{
    case Fuel = 'fuel';
    case Repair = 'repair';
    case Maintenance = 'maintenance';
    case Mobilization = 'mobilization';
    case Rental = 'rental';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
