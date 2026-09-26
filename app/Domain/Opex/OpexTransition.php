<?php

namespace App\Domain\Opex;

/**
 * Gated transitions on an OPEX period.
 *
 * Only the close is gated. Every earlier stage advance has its own reasons to be
 * allowed — a period still in capture has nothing to explain yet, and refusing
 * to cut it off would stop the month.
 */
enum OpexTransition: string
{
    case CloseMonth = 'close-month';
}
