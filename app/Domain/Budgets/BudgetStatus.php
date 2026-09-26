<?php

namespace App\Domain\Budgets;

/**
 * Budget lifecycle status.
 *
 * F14 gates a PR on "a signed contract AND an opened budget", so `Open` is the
 * state the Gates service (P0-11) will test for. A draft budget is editable; an
 * open one is the number the budget check resolves against.
 */
enum BudgetStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
