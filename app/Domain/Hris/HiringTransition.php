<?php

namespace App\Domain\Hris;

/**
 * Gated transitions in the hiring chain.
 */
enum HiringTransition: string
{
    case Work = 'work';
}
