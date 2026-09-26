<?php

namespace App\Filament\Widgets;

use App\Domain\Access\ScreenAccess;
use App\Domain\Reporting\KpiService;
use App\Models\User;
use Filament\Widgets\ChartWidget;

/**
 * Budget against actual cost, per project.
 *
 * Projects with no budget are left out rather than drawn at zero: a project
 * budgeted at nothing and a project nobody has budgeted yet look identical on a
 * bar chart, and only one of them is a problem.
 */
class BudgetVersusActualChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Budget versus actual';

    protected ?string $description = 'Open budgets against cost posted to the ledger. Projects with no budget are not shown.';

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ScreenAccess::class)->allows($user, static::class);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = app(KpiService::class)->budgetVersusActual();

        return [
            'datasets' => [
                [
                    'label' => 'Budget',
                    'data' => array_map(fn (array $row): float => (float) $row['budget'], $rows),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.6)',
                ],
                [
                    'label' => 'Actual',
                    'data' => array_map(fn (array $row): float => (float) $row['actual'], $rows),
                    'backgroundColor' => 'rgba(239, 68, 68, 0.6)',
                ],
            ],
            'labels' => array_map(fn (array $row): string => $row['project'], $rows),
        ];
    }
}
