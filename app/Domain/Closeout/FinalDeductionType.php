<?php

namespace App\Domain\Closeout;

/**
 * What is being taken off the final billing.
 *
 * Typed rather than free text because one of these is not like the others.
 * `BackCharge` rows are DERIVED — the amount was computed at punchlist clearing
 * by P5-02 and is copied, never entered. The rest are judgements somebody makes
 * at final billing and has to describe.
 */
enum FinalDeductionType: string
{
    case BackCharge = 'back_charge';
    case LiquidatedDamages = 'liquidated_damages';
    case ClientDeduction = 'client_deduction';
    case Other = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isDerived(): bool
    {
        return $this === self::BackCharge;
    }
}
