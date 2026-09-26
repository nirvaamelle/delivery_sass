<?php

namespace App\Domain\Posting;

/**
 * What a posting is, in P&L terms.
 *
 * These are the five endpoints the four chains resolve to — PLAN.md §1's table
 * of where each chain lands. Categorising at posting time is what lets the P&L
 * be assembled by summing rather than by classifying after the fact.
 */
enum LedgerCategory: string
{
    case Revenue = 'revenue';
    case Material = 'material';
    case Subcontract = 'subcontract';
    case Labor = 'labor';
    case Overhead = 'overhead';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
