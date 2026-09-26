<?php

namespace App\Domain\Hris;

/**
 * Gated transitions on a payroll run.
 */
enum PayrollTransition: string
{
    case ApproveRegister = 'approve-register';
}
