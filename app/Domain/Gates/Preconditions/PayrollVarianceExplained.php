<?php

namespace App\Domain\Gates\Preconditions;

use App\Domain\Gates\Precondition;
use App\Domain\Hris\PayrollVarianceService;
use App\Models\PayrollRun;
use App\Models\Project;

/**
 * F10: no payroll register approved while a project's variance against the last
 * cutoff is unexplained.
 *
 * PHASE-PLAN.md calls this "a second, independent variance gate" alongside the
 * OPEX close, and the independence is the point: payroll cannot borrow the OPEX
 * gate because they close on different calendars (F3) and answer to different
 * reviewers.
 *
 * It BLOCKS rather than warns. A variance review that can be skipped is skipped
 * on the busy cutoffs — which are precisely the cutoffs with the variances.
 */
class PayrollVarianceExplained implements Precondition
{
    public function name(): string
    {
        return 'payroll-variance-explained';
    }

    public function passes(object $subject): bool
    {
        if (! $subject instanceof PayrollRun) {
            return false;
        }

        return app(PayrollVarianceService::class)->unexplained($subject) === [];
    }

    public function failureMessage(object $subject): string
    {
        if (! $subject instanceof PayrollRun) {
            return 'Not a payroll run.';
        }

        $codes = Project::query()
            ->whereIn('id', app(PayrollVarianceService::class)->unexplained($subject))
            ->orderBy('code')
            ->pluck('code')
            ->implode(', ');

        return sprintf(
            'Labour cost against the last cutoff is unexplained for: %s. F10 requires the variance explained per project before the register is approved.',
            $codes,
        );
    }
}
