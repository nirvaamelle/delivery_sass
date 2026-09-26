<?php

namespace App\Filament\Pages;

use App\Domain\Access\ScreenAccess;
use App\Domain\Hris\ThirteenthMonthService;
use App\Models\Organization;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * 13th month pay, per employee, for a year — the computation, not the payout.
 *
 * Read from approved and released registers through ThirteenthMonthService, so
 * the figure is what was actually paid in basic pay and leave, never a rate
 * multiplied out. How and when it is paid is the client's policy and is not
 * built (DECISIONS-PENDING.md).
 */
class ThirteenthMonthPay extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = '13th month pay';

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 60;

    protected static ?string $title = '13th month pay';

    protected string $view = 'filament.pages.thirteenth-month-pay';

    /**
     * A custom page, so Filament's default is to allow every signed-in user.
     * It is pay, so it follows the payroll screens (config/access.php).
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(ScreenAccess::class)->allows($user, static::class);
    }

    public int $year;

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(ThirteenthMonthService::class);
        $companies = [];

        foreach (Organization::query()->orderBy('name')->get() as $organization) {
            $rows = $service->forOrganization($organization, $this->year);

            if ($rows !== []) {
                $companies[] = ['organization' => $organization, 'rows' => $rows];
            }
        }

        return [
            'companies' => $companies,
            'ceiling' => (string) config('payroll.benefits.thirteenth_month.tax_exempt_ceiling', '90000.00'),
        ];
    }
}
