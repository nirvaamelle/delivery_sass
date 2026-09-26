<?php

namespace App\Filament\Widgets;

use App\Domain\Access\ScreenAccess;
use App\Domain\Reporting\KpiService;
use App\Models\User;
use Filament\Widgets\Widget;

/**
 * The short list of things somebody has to act on.
 *
 * **An empty list is the healthy state, and it says so rather than disappearing.**
 * A dashboard panel that vanishes when it is empty teaches people that its
 * absence means nothing was checked; one that says "nothing needs attention"
 * tells them it was checked and the answer was none.
 *
 * Only items with a deadline or a consequence appear — an unexplained variance
 * blocks a period close, a ninety-day receivable is money the company may not
 * get. Listing everything teaches people to ignore the panel.
 */
class NeedsAttention extends Widget
{
    protected static ?int $sort = 0;

    protected string $view = 'filament.widgets.needs-attention';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ScreenAccess::class)->allows($user, static::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['items' => app(KpiService::class)->needsAttention()];
    }
}
