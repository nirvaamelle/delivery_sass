<?php

namespace App\Domain\Closeout;

/**
 * Where a turnover line gets its answer.
 *
 * The distinction is the point of P5-04. A `Filed` line is satisfied by a
 * person putting a reference on file and signing for it. The register-backed
 * lines are not satisfiable that way at all: they read P5-03's warranty
 * register and P2-01's permit register, so "warranty certificates: collected"
 * cannot be asserted by whoever is keenest to close the project.
 */
enum TurnoverItemSource: string
{
    case Filed = 'filed';
    case WarrantyRegister = 'warranty_register';
    case PermitRegister = 'permit_register';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Can a human put this line on file by hand?
     */
    public function isFiled(): bool
    {
        return $this === self::Filed;
    }
}
