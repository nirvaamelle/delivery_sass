<?php

namespace App\Filament\Pages;

use App\Domain\Access\ScreenAccess;
use App\Domain\Opex\ConsolidationService;
use App\Domain\Opex\CostPerUnitService;
use App\Domain\Support\Money;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * The project P&L and cash requirement — slide 8's consolidation outputs.
 *
 * A page rather than a resource, because this is not a list of records. It is
 * one report assembled from the ledger, and giving it a resource would imply
 * there is a `profit_and_loss` table somebody could edit.
 *
 * **Everything on it is summed at read time from `project_cost_ledger`.** No
 * stored totals, no snapshot rows: the consolidation is a view of the ledger,
 * and a cached one would be a second set of numbers to reconcile — which is
 * precisely the problem the ledger was introduced to end.
 */
class ProjectProfitAndLoss extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $navigationLabel = 'Project P&L';

    protected static string|UnitEnum|null $navigationGroup = 'Reporting';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Project P&L and cash requirement';

    protected string $view = 'filament.pages.project-profit-and-loss';

    /**
     * A custom page, so Filament's default is to allow every signed-in user.
     * The P&L is finance's and the managing director's (config/access.php).
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(ScreenAccess::class)->allows($user, static::class);
    }

    public int $year;

    public int $month;

    public function mount(): void
    {
        // Last month by default: the consolidation is written up in days 3–5 of
        // the following month, so the month somebody opens this page to read is
        // almost always the one just closed.
        $lastMonth = now()->subMonth();

        $this->year = (int) $lastMonth->year;
        $this->month = (int) $lastMonth->month;
    }

    /**
     * Step one month at a time, and jump to any month.
     *
     * The page used to compute a month on mount and offer no way to change it,
     * so the question it exists to answer — "how did we do in March?" — could
     * not be asked at all.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousMonth')
                ->label('Previous')
                ->icon('heroicon-m-chevron-left')
                ->color('gray')
                ->action(fn () => $this->shiftMonth(-1)),

            Action::make('choosePeriod')
                ->label(fn (): string => $this->periodLabel())
                ->icon('heroicon-m-calendar')
                ->color('gray')
                ->modalHeading('Show another month')
                ->modalSubmitActionLabel('Show')
                ->fillForm(fn (): array => ['month' => $this->month, 'year' => $this->year])
                ->schema([
                    Select::make('month')
                        ->required()
                        ->options(collect(range(1, 12))
                            ->mapWithKeys(fn (int $m): array => [$m => Carbon::create(null, $m, 1)->format('F')])
                            ->all()),

                    Select::make('year')
                        ->required()
                        ->options(collect(range((int) now()->year - 5, (int) now()->year + 1))
                            ->mapWithKeys(fn (int $y): array => [$y => (string) $y])
                            ->all()),
                ])
                ->action(function (array $data): void {
                    $this->year = (int) $data['year'];
                    $this->month = (int) $data['month'];
                }),

            Action::make('nextMonth')
                ->label('Next')
                ->icon('heroicon-m-chevron-right')
                ->iconPosition(IconPosition::After)
                ->color('gray')
                ->action(fn () => $this->shiftMonth(1)),
        ];
    }

    private function shiftMonth(int $by): void
    {
        $moved = Carbon::create($this->year, $this->month, 1)->addMonthsNoOverflow($by);

        $this->year = (int) $moved->year;
        $this->month = (int) $moved->month;
    }

    public function periodLabel(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $consolidation = app(ConsolidationService::class);
        $costPerUnit = app(CostPerUnitService::class);

        $rows = [];

        foreach (Project::query()->orderBy('code')->get() as $project) {
            $rows[] = [
                'project' => $project,
                'pnl' => $consolidation->profitAndLoss($project, $this->year, $this->month),
                'cash' => $consolidation->cashRequirement($project, $this->year, $this->month),
                // PLAN.md §7 step 9. Life to date, not for the month: a cost per
                // point of accomplishment for one month's cost against the whole
                // project's percentage would be a meaningless ratio.
                'unit' => $costPerUnit->forProject($project),
            ];
        }

        return [
            'period' => $this->periodLabel(),
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'organizations' => Organization::query()->orderBy('code')->get(),
        ];
    }

    /**
     * The portfolio line, summed in bcmath from the same figures the rows show.
     *
     * Summed here rather than in the view so the page and its per-project rows
     * cannot disagree: one arithmetic, read twice.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{revenue: string, cost: string, gross_profit: string, margin_percent: ?string, cash: string}
     */
    private function totals(array $rows): array
    {
        $revenue = '0.0000';
        $cost = '0.0000';
        $cash = '0.0000';

        foreach ($rows as $row) {
            $revenue = Money::sum($revenue, $row['pnl']['revenue']);
            $cost = Money::sum($cost, $row['pnl']['total_cost']);
            $cash = Money::sum($cash, $row['cash']['net_requirement']);
        }

        $grossProfit = bcsub($revenue, $cost, Money::SCALE);

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'gross_profit' => $grossProfit,
            // Null, not zero: nothing billed is not the same as no margin.
            'margin_percent' => Money::isZero($revenue)
                ? null
                : bcdiv(bcmul($grossProfit, '100', Money::WORKING_SCALE), $revenue, 2),
            'cash' => $cash,
        ];
    }
}
