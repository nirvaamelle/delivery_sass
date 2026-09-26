<?php

namespace App\Domain\Equipment;

use App\Domain\Support\Money;
use App\Models\DepreciationSchedule;
use App\Models\Equipment;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Straight-line depreciation, computed once into a schedule — F5.
 *
 * **A schedule, not a formula evaluated monthly.** The difference matters: a
 * number recomputed each month by whoever opens the report changes when somebody
 * edits the machine's useful life two years in, and every month already posted
 * silently disagrees with the report that produced it. The rows are written once
 * and read thereafter, the same discipline as the receiving report snapshotting
 * its ordered quantity.
 *
 * **The instalments sum to the depreciable base exactly.** 1,000,000 over 60
 * months is 16,666.6667 rounded, and sixty of those is 1,000,000.0020 — two
 * tenths of a centavo of asset that never existed, posted to a project as real
 * cost. Across a fleet and over years, that is a reconciliation difference
 * nobody can source. So the final period carries the remainder, and the total is
 * an assertion rather than an aspiration.
 *
 * Only owned equipment depreciates. A rental is an expense as it is incurred,
 * and depreciating it would capitalise a cost the company has already paid in
 * full and count it a second time.
 *
 * PLACEHOLDER: Part D item 15 — neither the method nor the useful life is
 * confirmed. Both are columns on the machine, so the client answering is a data
 * change and not a code change.
 */
class DepreciationService
{
    /**
     * Build the whole schedule for a machine.
     *
     * @return Collection<int, DepreciationSchedule>
     *
     * @throws NotDepreciableException when the machine is not owned, or has no
     *                                 depreciable base or life
     * @throws DomainException when a schedule already exists
     */
    public function generate(Equipment $item): Collection
    {
        if ($item->ownership !== Ownership::Owned) {
            throw new NotDepreciableException(sprintf(
                'Equipment %s is %s, not owned. A rental is an expense as it is incurred — depreciating it would capitalise a cost already paid in full and count it twice.',
                $item->code,
                $item->ownership->value,
            ));
        }

        if ($item->schedules()->exists()) {
            throw new DomainException(sprintf(
                'Equipment %s already has a depreciation schedule. Two schedules mean two charges a month, both looking correct.',
                $item->code,
            ));
        }

        if ($item->acquired_on === null) {
            throw new NotDepreciableException(sprintf(
                'Equipment %s has no acquisition date, so there is no month for depreciation to start in.',
                $item->code,
            ));
        }

        $months = $item->useful_life_months;

        if ($months < 1) {
            throw new NotDepreciableException(sprintf(
                'Equipment %s has a useful life of %d months. Depreciation over no time is not a schedule.',
                $item->code,
                $months,
            ));
        }

        $base = bcsub((string) $item->acquisition_cost, (string) $item->salvage_value, Money::SCALE);

        if (bccomp($base, '0', Money::SCALE) <= 0) {
            throw new NotDepreciableException(sprintf(
                'Equipment %s cost %s against a salvage value of %s, so there is nothing to depreciate.',
                $item->code,
                $item->acquisition_cost,
                $item->salvage_value,
            ));
        }

        // Divided at the working scale, rounded once. Every instalment but the
        // last takes this figure.
        $instalment = Money::round(bcdiv($base, (string) $months, Money::WORKING_SCALE));

        return DB::transaction(function () use ($item, $months, $base, $instalment): Collection {
            $period = $item->acquired_on->copy()->startOfMonth();
            $running = '0.0000';

            for ($n = 1; $n <= $months; $n++) {
                $amount = $n === $months
                    // The remainder, so the schedule totals the base exactly
                    // rather than the sum of sixty roundings.
                    ? bcsub($base, $running, Money::SCALE)
                    : $instalment;

                DepreciationSchedule::query()->create([
                    'equipment_id' => $item->getKey(),
                    'period_year' => (int) $period->year,
                    'period_month' => (int) $period->month,
                    'amount' => $amount,
                ]);

                $running = Money::sum($running, $amount);
                $period = $period->addMonth();
            }

            return $this->scheduleFor($item);
        });
    }

    /**
     * The schedule in period order.
     *
     * @return Collection<int, DepreciationSchedule>
     */
    public function scheduleFor(Equipment $item): Collection
    {
        return $item->schedules()
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get();
    }

    /**
     * What the schedule comes to — summed in bcmath, never SQL SUM().
     */
    public function totalScheduled(Equipment $item): string
    {
        $total = '0.0000';

        foreach ($item->schedules()->pluck('amount') as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }

    /**
     * The instalment due for one period, if the schedule covers it.
     */
    public function forPeriod(Equipment $item, int $year, int $month): ?DepreciationSchedule
    {
        return $item->schedules()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();
    }
}
