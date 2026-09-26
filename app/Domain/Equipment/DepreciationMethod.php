<?php

namespace App\Domain\Equipment;

/**
 * How an owned machine's cost is spread over its life.
 *
 * Only straight line exists today, and it is stored per machine rather than
 * assumed globally. PLACEHOLDER: Part D item 15 — the client has confirmed
 * neither the method nor the useful life, so the column is what changes when
 * they do, not the code.
 */
enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
