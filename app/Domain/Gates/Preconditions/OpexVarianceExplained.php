<?php

namespace App\Domain\Gates\Preconditions;

use App\Domain\Gates\Precondition;
use App\Domain\Opex\BudgetActualService;
use App\Domain\Opex\OpexStage;
use App\Models\CostCode;
use App\Models\OpexPeriod;

/**
 * Slide 8's day 30: no close while a variance above 10% is unexplained.
 *
 * The OPEX twin of F10's payroll gate, and the pair is deliberately two gates
 * rather than one shared one: the chains close on different calendars — F3's
 * whole finding — and answer to different reviewers.
 *
 * It BLOCKS rather than warns. A month-end review that can be skipped is skipped
 * in the months with the variances, which are the months worth reviewing.
 *
 * The precondition only bites when the period is LEAVING budget review. Every
 * earlier transition has its own reasons to be allowed — capture to cutoff must
 * happen before anybody has coded anything, let alone explained it.
 */
class OpexVarianceExplained implements Precondition
{
    public function name(): string
    {
        return 'opex-variance-explained';
    }

    public function passes(object $subject): bool
    {
        if (! $subject instanceof OpexPeriod) {
            return false;
        }

        // Only the close itself is gated. A period still in capture has nothing
        // to explain yet, and refusing to cut it off would stop the month.
        if ($subject->stage !== OpexStage::BudgetReview) {
            return true;
        }

        return app(BudgetActualService::class)->unexplained($subject) === [];
    }

    public function failureMessage(object $subject): string
    {
        if (! $subject instanceof OpexPeriod) {
            return 'Not an OPEX period.';
        }

        $codes = CostCode::query()
            ->whereIn('id', app(BudgetActualService::class)->unexplained($subject))
            ->orderBy('code')
            ->pluck('code')
            ->implode(', ');

        return sprintf(
            'Budget variance is unexplained for: %s. Slide 8 requires a variance above the threshold explained in writing before the month closes.',
            $codes,
        );
    }
}
