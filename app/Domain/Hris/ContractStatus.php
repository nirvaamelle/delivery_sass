<?php

namespace App\Domain\Hris;

/**
 * Whether an employment contract has actually been signed.
 *
 * `Issued` is a real state and not a default nobody looks at: slide 7's rule is
 * that the contract is signed BEFORE the first shift, and the gate that enforces
 * it is reading this. A model where a contract is simply "created" and assumed
 * agreed would make the rule unenforceable by construction.
 */
enum ContractStatus: string
{
    case Issued = 'issued';
    case Signed = 'signed';
    case Expired = 'expired';
    case Terminated = 'terminated';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
