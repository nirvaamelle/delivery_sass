<?php

namespace App\Domain\Procurement;

/**
 * Why a purchase went to one supplier without a canvass.
 *
 * Enumerated so the exemptions can be counted. A quarterly review that can ask
 * "how many emergency sole sources did we have, and were they all really
 * emergencies" is the thing that stops the exemption becoming the norm — and a
 * free-text reason records each incident while being impossible to total.
 */
enum SoleSourceReason: string
{
    /** Only one supplier is licensed or authorised to supply it. */
    case ProprietaryItem = 'proprietary_item';

    /** Work stoppage or safety risk; no time to canvass. */
    case EmergencyRequirement = 'emergency_requirement';

    /** Must match equipment or materials already on site. */
    case Compatibility = 'compatibility';

    /** The client named the supplier in the contract. */
    case ClientSpecified = 'client_specified';

    case Other = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
