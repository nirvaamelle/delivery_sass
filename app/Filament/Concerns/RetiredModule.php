<?php

namespace App\Filament\Concerns;

/**
 * A module the pivot retired — spec §8.
 *
 * Navigation only. `canViewAny()` still decides who may open the screen, and
 * the route still resolves, so a bookmark from before the pivot does not 404
 * and nobody mistakes a hidden menu entry for a secured one.
 *
 * The list lives in `config/modules.php` rather than in a property on each
 * resource, because "which modules does this installation run" is a question
 * about the deployment, not about the screen.
 */
trait RetiredModule
{
    public static function shouldRegisterNavigation(): bool
    {
        $retired = config('modules.retired', []);

        return ! (is_array($retired) && in_array(static::class, $retired, true));
    }
}
