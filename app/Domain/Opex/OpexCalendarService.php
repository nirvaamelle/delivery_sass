<?php

namespace App\Domain\Opex;

use App\Domain\Gates\Gatekeeper;
use App\Domain\Support\Money;
use App\Models\OpexPeriod;
use App\Models\Organization;
use App\Models\PayrollDeduction;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Slide 8's monthly calendar as a state machine — P4-03 — and the day-26 job
 * that closes F17.
 *
 * **A state machine, not a set of dates.** PHASE-PLAN.md is explicit that the
 * scheduler drives it, and the difference matters: a stage is entered by a
 * transition somebody or something performed. Reading the day off the clock
 * would mean a month whose scheduler did not run on the 26th silently behaves as
 * though it had — and the cutoff is the stage that stops expenses booking into a
 * period whose numbers have been reported.
 *
 * So stages advance one at a time and never backwards. Skipping from capture to
 * consolidation would build a consolidation from whatever happened to be
 * captured, with nobody having coded or validated it; going backwards would
 * change numbers somebody has already reported, and a correction is a later
 * period's business.
 *
 * **F17 fires at cutoff.** Slide 8: advances are "liquidated or charged to the
 * next payroll". PHASE-PLAN.md names this as the kind of cross-chain link that
 * quietly never ships, so two things make it real rather than notional: the
 * advance is stamped `ChargedToPayroll` so no retry or second scheduler can
 * sweep it twice, and the charge is a `payroll_deductions` row the next payroll
 * run reads — not a note somebody is expected to act on.
 */
class OpexCalendarService
{
    public function __construct(
        private readonly Gatekeeper $gates,
    ) {}

    /**
     * Open a month in the capture stage.
     */
    public function open(Organization $organization, int $year, int $month): OpexPeriod
    {
        return DB::transaction(fn (): OpexPeriod => OpexPeriod::query()->create([
            'organization_id' => $organization->getKey(),
            'period_year' => $year,
            'period_month' => $month,
            'stage' => OpexStage::Capture,
            'opened_at' => now(),
        ]));
    }

    /**
     * Move a period to the next stage.
     *
     * @throws DomainException when the move skips a stage or goes backwards
     */
    public function advanceTo(OpexPeriod $period, OpexStage $stage, ?User $by = null, ?string $remarks = null): OpexPeriod
    {
        $from = $period->stage;

        if ($stage->sequence() <= $from->sequence()) {
            throw new DomainException(sprintf(
                'Period %d-%02d is at %s. A calendar does not run backwards — reopening a period changes numbers somebody has already reported, and a correction belongs to a later period.',
                $period->period_year,
                $period->period_month,
                $from->value,
            ));
        }

        if ($stage->sequence() !== $from->sequence() + 1) {
            throw new DomainException(sprintf(
                'Period %d-%02d is at %s and cannot jump to %s. Slide 8 runs capture, cutoff, coding, validation, consolidation, budget review, reporting — in that order.',
                $period->period_year,
                $period->period_month,
                $from->value,
                $stage->value,
            ));
        }

        if ($stage === OpexStage::Reporting) {
            // Slide 8's day 30, through the Gatekeeper like every other gate in
            // the system: the close is where the variance review has to have
            // happened, and a review that can be skipped is skipped in exactly
            // the months that needed it.
            $this->gates->assert($period, OpexTransition::CloseMonth->value);
        }

        return DB::transaction(function () use ($period, $from, $stage, $by, $remarks): OpexPeriod {
            $period->update([
                'stage' => $stage,
                'cutoff_at' => $stage === OpexStage::Cutoff ? now() : $period->cutoff_at,
                'closed_at' => $stage === OpexStage::Closed ? now() : $period->closed_at,
            ]);

            $period->transitions()->create([
                'from_stage' => $from,
                'to_stage' => $stage,
                'performed_at' => now(),
                'performed_by_user_id' => $by?->getKey(),
                'remarks' => $remarks,
            ]);

            if ($stage === OpexStage::Cutoff) {
                // F17. The advance sweep is part of the cutoff transition rather
                // than a separate job, so a cut-off period cannot exist without
                // it having run.
                $this->chargeUnliquidatedAdvances($period->refresh(), $by);
            }

            return $period->refresh();
        });
    }

    /**
     * May an expense still be captured into this period?
     *
     * An unopened period is open: a company that has not set up next month's
     * calendar is still capturing into it, and refusing would stop work for an
     * administrative reason. What must not happen is capturing into a period
     * that has been CUT OFF.
     */
    public function isCaptureOpen(Organization $organization, int $year, int $month): bool
    {
        $period = $this->find($organization, $year, $month);

        return $period === null || $period->stage === OpexStage::Capture;
    }

    public function find(Organization $organization, int $year, int $month): ?OpexPeriod
    {
        return OpexPeriod::query()
            ->where('organization_id', $organization->getKey())
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();
    }

    /**
     * F17: charge every unliquidated advance to the next payroll.
     *
     * @return array<int, PayrollDeduction>
     */
    public function chargeUnliquidatedAdvances(OpexPeriod $period, ?User $by = null): array
    {
        $organization = $period->organization()->sole();
        $asOf = $this->cutoffDateFor($period);

        $advances = app(CashAdvanceService::class)->unliquidatedAsOf($organization, $asOf);

        $raised = [];

        foreach ($advances as $advance) {
            // Belt and braces beside the unique key on cash_advance_id: the key
            // is what actually guarantees it, this is what keeps the sweep quiet
            // rather than throwing on a retry.
            if (PayrollDeduction::query()->where('cash_advance_id', $advance->getKey())->exists()) {
                continue;
            }

            $outstanding = app(CashAdvanceService::class)->outstandingFor($advance);

            if (Money::isZero($outstanding)) {
                continue;
            }

            $raised[] = PayrollDeduction::query()->create([
                'employee_id' => $advance->employee_id,
                'cash_advance_id' => $advance->getKey(),
                'reason' => sprintf('Unliquidated cash advance %s at %d-%02d cutoff.', $advance->number, $period->period_year, $period->period_month),
                'amount' => $outstanding,
                'period_year' => $period->period_year,
                'period_month' => $period->period_month,
                'raised_at' => now(),
            ]);

            $advance->update([
                'status' => CashAdvanceStatus::ChargedToPayroll,
                'charged_to_payroll_on' => $asOf,
                'charged_amount' => $outstanding,
            ]);
        }

        return $raised;
    }

    /**
     * The date the sweep looks back to.
     *
     * Slide 8's day 26, of the period's own month — not today. A job rerun in
     * July must sweep May as May was on the 26th, or a late rerun would pull in
     * advances that belong to June's cutoff.
     */
    private function cutoffDateFor(OpexPeriod $period): CarbonInterface
    {
        $day = OpexStage::Cutoff->scheduledDay() ?? 26;

        return Carbon::create($period->period_year, $period->period_month, $day)->endOfDay();
    }
}
