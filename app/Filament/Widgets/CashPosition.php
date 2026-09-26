<?php

namespace App\Filament\Widgets;

use App\Domain\Access\ScreenAccess;
use App\Domain\Reporting\KpiService;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What is owed to the company, what it owes, and what is being held back.
 *
 * Retention sits beside the net rather than inside it: it is owed to the company
 * but not collectable until the defects liability period ends, and adding it to
 * a cash figure would overstate what can actually be spent.
 */
class CashPosition extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ScreenAccess::class)->allows($user, static::class);
    }

    protected function getStats(): array
    {
        $cash = app(KpiService::class)->cashPosition();

        return [
            Stat::make('Receivable', self::money($cash['receivable']))
                ->description('Invoiced and not yet collected')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success'),

            Stat::make('Payable', self::money($cash['payable']))
                ->description('Approved vouchers not yet paid')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning'),

            Stat::make('Retention held', self::money($cash['retention_held']))
                ->description('Owed to us, released after the defects period')
                ->icon('heroicon-o-lock-closed'),

            Stat::make('Net position', self::money($cash['net']))
                ->description('Receivable less payable, retention excluded')
                ->icon('heroicon-o-banknotes')
                ->color(bccomp($cash['net'], '0', 4) < 0 ? 'danger' : 'success'),
        ];
    }

    private static function money(string $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }
}
