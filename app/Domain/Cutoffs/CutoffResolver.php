<?php

namespace App\Domain\Cutoffs;

use App\Models\CutoffCalendar;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Answers "is this period still open?" — PLAN.md §5's cutoff control.
 *
 * Two dates are in play and conflating them is the mistake this class exists to
 * prevent. The DOCUMENT's date decides which period it belongs to. The POSTING
 * moment decides whether that period is still accepting entries. An expense
 * dated 3 May, booked on 27 May, belongs to May and is late — a day-26 OPEX
 * cutoff rejects it even though its own date is early in the month.
 */
class CutoffResolver
{
    /**
     * Find the calendar governing a document, preferring a project override.
     *
     * @throws CutoffNotConfiguredException when no calendar covers the date
     */
    public function resolve(
        CutoffType $type,
        CarbonInterface $documentDate,
        ?Project $project = null
    ): CutoffCalendar {
        $calendar = CutoffCalendar::query()
            ->where('cutoff_type', $type)
            ->whereDate('period_start', '<=', $documentDate)
            ->whereDate('period_end', '>=', $documentDate)
            ->where(function (Builder $query) use ($project): void {
                $query->whereNull('project_id');

                if ($project !== null) {
                    $query->orWhere('project_id', $project->getKey());
                }
            })
            // Non-NULL project_id sorts first, so an override wins over the
            // organization-wide row for the same period.
            ->orderByRaw('project_id IS NULL')
            ->first();

        if ($calendar === null) {
            throw new CutoffNotConfiguredException(sprintf(
                'No %s cutoff calendar covers %s.',
                $type->value,
                $documentDate->toDateString()
            ));
        }

        return $calendar;
    }

    /**
     * Is the period covering this document still open?
     *
     * @throws CutoffNotConfiguredException when no calendar covers the date
     */
    public function isOpen(
        CutoffType $type,
        CarbonInterface $documentDate,
        ?Project $project = null,
        ?CarbonInterface $postedAt = null
    ): bool {
        $calendar = $this->resolve($type, $documentDate, $project);

        return ($postedAt ?? now())->lessThanOrEqualTo($calendar->cutoff_at);
    }

    /**
     * Refuse the posting unless the period is still open.
     *
     * @throws CutoffNotConfiguredException when no calendar covers the date
     * @throws CutoffClosedException when the period has already closed
     */
    public function assertOpen(
        CutoffType $type,
        CarbonInterface $documentDate,
        ?Project $project = null,
        ?CarbonInterface $postedAt = null
    ): void {
        $calendar = $this->resolve($type, $documentDate, $project);
        $moment = $postedAt ?? now();

        if ($moment->greaterThan($calendar->cutoff_at)) {
            throw new CutoffClosedException(sprintf(
                'The %s period %s to %s closed at %s; nothing books after cutoff.',
                $type->value,
                $calendar->period_start->toDateString(),
                $calendar->period_end->toDateString(),
                $calendar->cutoff_at->toDateTimeString()
            ));
        }
    }
}
