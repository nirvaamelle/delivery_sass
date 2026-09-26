<?php

namespace App\Domain\Vendors;

/**
 * Why a vendor was suspended or removed.
 *
 * The reasons come straight from PLAN.md §5's scorecard rules. Enumerated
 * rather than free text so the scorecard job can act on them and so a quarterly
 * review can count them — free text records the incident but cannot be totalled.
 */
enum VendorSuspensionReason: string
{
    /** Two late deliveries in a quarter. */
    case LateDeliveries = 'late_deliveries';

    /** Rejection rate above 5%. */
    case RejectionRate = 'rejection_rate';

    /** F11: a warranty claim is its own trigger. */
    case WarrantyClaim = 'warranty_claim';

    /** Falsified documents — immediate removal, not suspension. */
    case FalsifiedDocuments = 'falsified_documents';

    case Other = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
