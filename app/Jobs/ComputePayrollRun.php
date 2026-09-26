<?php

namespace App\Jobs;

use App\Domain\Hris\PayrollService;
use App\Models\PayrollRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Computes one payroll run off the request cycle — slide 7's "queued payroll run".
 *
 * A payroll for a whole organization computed inside a web request is a timeout
 * waiting to happen, and a timeout mid-computation leaves a half-written register
 * behind. The computation itself is one database transaction in PayrollService,
 * so a failed job leaves the run exactly as it was queued.
 *
 * Carries the run's id rather than the model, so a retried job reloads the run's
 * current state instead of acting on a snapshot from when it was dispatched.
 */
class ComputePayrollRun implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $payrollRunId,
    ) {}

    public function handle(PayrollService $payroll): void
    {
        $payroll->compute(PayrollRun::query()->findOrFail($this->payrollRunId));
    }
}
