<?php

namespace App\Domain\Mobilization;

/**
 * The mobilization checklist — slide 3's step 5 output.
 *
 * An enum rather than rows somebody types in, because the checklist is the
 * document: a list assembled by hand is a list that can be assembled short, and
 * the one item left off is the one nobody remembers was meant to be there.
 * Adding an item here adds it to every mobilization opened afterwards.
 *
 * `PermitsSecured` is the item with teeth. Slide 3 says "site setup and permits
 * secured", and a tick box anybody can reach records intent rather than fact —
 * so this one is backed by an actual permit record with an actual expiry, and
 * `MobilizationService` refuses to tick it otherwise.
 */
enum ChecklistItem: string
{
    case SiteOfficeEstablished = 'site_office_established';
    case PermitsSecured = 'permits_secured';
    case UtilitiesConnected = 'utilities_connected';
    case SafetyOfficerAssigned = 'safety_officer_assigned';
    case EquipmentOnSite = 'equipment_on_site';
    case ManpowerDeployed = 'manpower_deployed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Every item is required today.
     *
     * Stated as a method rather than assumed, because the moment an item is
     * added that is genuinely optional — a site-specific one — the completion
     * rule needs to know the difference, and discovering that then means
     * changing the rule rather than the list.
     */
    public function isRequired(): bool
    {
        return true;
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
