<?php

namespace App\Filament\Widgets;

use App\Domain\Access\ScreenAccess;
use App\Domain\Reporting\KpiService;
use App\Models\User;
use Filament\Widgets\ChartWidget;

/**
 * The S-curve PLAN.md §2 names: cumulative revenue against cumulative cost.
 *
 * Cumulative is what makes it a curve. A month with no postings holds the level
 * of the month before rather than dropping to zero, because the question the
 * chart answers is "where are we, to date" and not "what happened in June".
 *
 * The gap between the two lines is gross profit, which is the point of drawing
 * them on one axis.
 */
class SCurveChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Revenue and cost to date';

    protected ?string $description = 'Cumulative, from the project cost ledger. The gap between the lines is gross profit.';

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ScreenAccess::class)->allows($user, static::class);
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $curve = app(KpiService::class)->cumulativeCurve(months: 12);

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    // Chart.js needs numbers; the arithmetic behind them was
                    // done in bcmath, and this is the last possible moment to
                    // cast — a float here cannot corrupt a stored figure.
                    'data' => array_map(fn (string $v): float => (float) $v, $curve['revenue']),
                    'borderColor' => 'rgb(34, 197, 94)',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Cost',
                    'data' => array_map(fn (string $v): float => (float) $v, $curve['cost']),
                    'borderColor' => 'rgb(239, 68, 68)',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $curve['labels'],
        ];
    }
}
