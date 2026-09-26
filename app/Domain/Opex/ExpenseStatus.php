<?php

namespace App\Domain\Opex;

/**
 * Where an expense stands in slide 8's monthly calendar.
 *
 * `Returned` is a real state rather than a deletion, and that is what makes the
 * permanent bar possible: the rejected expense stays on record, carrying the
 * reason it was returned and the period it was returned from. A deleted expense
 * could simply be re-entered.
 */
enum ExpenseStatus: string
{
    case Captured = 'captured';
    case Coded = 'coded';
    case Validated = 'validated';
    case Returned = 'returned';
    case Posted = 'posted';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
