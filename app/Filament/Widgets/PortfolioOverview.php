<?php

namespace App\Filament\Widgets;

use App\Domain\Access\ScreenAccess;
use App\Domain\Reporting\KpiService;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four figures an executive opens the system for.
 *
 * **Who sees this is decided the same way every screen is** — by
 * `config/access.php` through `ScreenAccess`. A dashboard widget is not exempt
 * from access control merely because it is a summary: company margin on a
 * foreman's home page is the same disclosure as the P&L screen they are refused.
 *
 * The figures themselves come from `KpiService`, which reads through the project
 * scope, so a project manager sees their own jobs added up rather than the
 * company's.
 */
class PortfolioOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ScreenAccess::class)->allows($user, static::class);
    }

    protected function getStats(): array
    {
        $portfolio = app(KpiService::class)->portfolio();

        return [
            Stat::make('Active projects', (string) $portfolio['active_projects'])
                ->description('Contract value '.self::money($portfolio['contract_value']))
                ->icon('heroicon-o-briefcase'),

            Stat::make('Revenue to date', self::money($portfolio['revenue']))
                ->description('Posted to the ledger')
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success'),

            Stat::make('Cost to date', self::money($portfolio['cost']))
                ->description('Material, subcontract, labour and overhead')
                ->icon('heroicon-o-arrow-trending-down'),

            // "Not yet billed" rather than 0%: zero margin on zero revenue reads
            // as working for nothing, which is a different and much worse
            // statement than not having billed yet.
            Stat::make('Gross margin', $portfolio['margin_percent'] === null
                ? 'Not yet billed'
                : $portfolio['margin_percent'].'%')
                ->description(self::money($portfolio['gross_profit']).' gross profit')
                ->icon('heroicon-o-scale')
                ->color(self::marginColour($portfolio['margin_percent'])),
        ];
    }

    private static function marginColour(?string $margin): string
    {
        if ($margin === null) {
            return 'gray';
        }

        return match (true) {
            bccomp($margin, '0', 2) < 0 => 'danger',
            bccomp($margin, '10', 2) < 0 => 'warning',
            default => 'success',
        };
    }

    private static function money(string $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }
}
