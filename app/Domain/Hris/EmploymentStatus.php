<?php

namespace App\Domain\Hris;

/**
 * Where somebody stands with the company.
 *
 * Resignation and termination are separate cases rather than one `inactive`
 * state, because final pay, re-hireability and the reason an auditor will ask
 * about all differ between them — and a boolean records none of it. The same
 * argument F11 made about suspending a vendor.
 */
enum EmploymentStatus: string
{
    case Active = 'active';
    case Resigned = 'resigned';
    case Terminated = 'terminated';
    case EndOfContract = 'end_of_contract';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Has this person left?
     */
    public function hasSeparated(): bool
    {
        return $this !== self::Active;
    }
}
