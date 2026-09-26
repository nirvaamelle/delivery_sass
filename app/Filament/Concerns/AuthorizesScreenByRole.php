<?php

namespace App\Filament\Concerns;

use App\Domain\Access\ScreenAccess;
use App\Models\User;

/**
 * Applied to every Filament resource: whether it appears at all.
 *
 * `canViewAny` is what Filament reads to build the navigation and to decide
 * whether the resource is reachable. It asks the domain rather than deciding
 * here — see `ScreenAccess` and `config/access.php`.
 *
 * On its own this is not enough, and `AuthorizesResourcePage` exists because
 * of it: with no policies, Filament's create/edit/view pages do not all defer to
 * this check, so a screen hidden from the menu could still answer its URL.
 */
trait AuthorizesScreenByRole
{
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ScreenAccess::class)->allows($user, static::class);
    }
}
