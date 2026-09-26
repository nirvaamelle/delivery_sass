<?php

namespace App\Domain\Opex;

/**
 * Slide 8's seven-stage monthly calendar, as a state machine.
 *
 * PHASE-PLAN.md: "The calendar is a state machine driven by the scheduler." The
 * distinction from a set of dates is that a stage is ENTERED by a transition
 * somebody or something performed. Reading the day off the clock would mean a
 * month whose scheduler did not run on the 26th silently behaves as though it
 * had — and the cutoff is the stage that stops expenses booking into a closed
 * period.
 *
 * `Reporting` falls in the FOLLOWING month (days 3–5), which is why the stage
 * belongs to the period rather than to a date: a period in reporting is being
 * written up while the next one is already capturing.
 */
enum OpexStage: string
{
    case Capture = 'capture';
    case Cutoff = 'cutoff';
    case Coding = 'coding';
    case Validation = 'validation';
    case Consolidation = 'consolidation';
    case BudgetReview = 'budget_review';
    case Reporting = 'reporting';
    case Closed = 'closed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Where this stage sits in slide 8's order.
     */
    public function sequence(): int
    {
        return match ($this) {
            self::Capture => 1,
            self::Cutoff => 2,
            self::Coding => 3,
            self::Validation => 4,
            self::Consolidation => 5,
            self::BudgetReview => 6,
            self::Reporting => 7,
            self::Closed => 8,
        };
    }

    /**
     * The stage that follows this one.
     */
    public function next(): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->sequence() === $this->sequence() + 1) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The day of the month slide 8 puts this stage on.
     *
     * Advisory only — the scheduler uses it to decide when to fire, and the state
     * machine decides whether the transition is legal. A stage that read this to
     * answer "where are we" would be reading the clock rather than the record.
     */
    public function scheduledDay(): ?int
    {
        return match ($this) {
            self::Capture => 1,
            self::Cutoff => 26,
            self::Coding => 27,
            self::Validation => 28,
            self::Consolidation => 29,
            self::BudgetReview => 30,
            // Days 3–5 of the FOLLOWING month.
            self::Reporting => 3,
            self::Closed => null,
        };
    }
}
