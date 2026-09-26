<?php

namespace App\Domain\Procurement;

/**
 * Subcontract lifecycle.
 */
enum SubcontractStatus: string
{
    case Awarded = 'awarded';
    case Mobilized = 'mobilized';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Terminated = 'terminated';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
