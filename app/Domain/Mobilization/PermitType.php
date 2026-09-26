<?php

namespace App\Domain\Mobilization;

/**
 * The permits slide 3 means by "site setup and permits secured".
 *
 * Typed rather than free text for the same reason vendor bonds are: a barangay
 * clearance is not a building permit, they cover different exposures, and a
 * checklist satisfied by "some permit exists" is satisfied by the wrong one.
 */
enum PermitType: string
{
    case BuildingPermit = 'building_permit';
    case BarangayClearance = 'barangay_clearance';
    case EnvironmentalClearance = 'environmental_clearance';
    case OccupancyPermit = 'occupancy_permit';
    case ExcavationPermit = 'excavation_permit';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
