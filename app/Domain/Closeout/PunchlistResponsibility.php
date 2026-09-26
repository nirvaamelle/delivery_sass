<?php

namespace App\Domain\Closeout;

/**
 * Who has to make a punchlist item good.
 *
 * Two cases, and the distinction is financial rather than organisational. Slide
 * 9 computes subcontractor back-charges at punchlist clearing and applies them
 * to final billing, so an item attributed to a subcontractor names a payable to
 * reduce. An item on our own forces names none — the cost is already in the
 * project through labour and materials, and charging it again would double it.
 */
enum PunchlistResponsibility: string
{
    case OwnForces = 'own_forces';
    case Subcontractor = 'subcontractor';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
