<?php

namespace App\Domain\Contracts;

/**
 * Contract lifecycle.
 *
 * `Signed` is the state F14 turns on: a contract in the drawer is not a
 * contract, and the failure mode the finding describes is precisely the one
 * where the paperwork exists so the work looks authorised.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case ForSignature = 'for_signature';
    case Signed = 'signed';
    case Terminated = 'terminated';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
