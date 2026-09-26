<?php

namespace App\Filament\Concerns;

use App\Domain\Access\ScreenAccess;
use App\Models\User;

/**
 * Applied to every page of every resource: whether the URL answers.
 *
 * Filament calls `canAccess` when a page mounts AND on every Livewire request
 * after that, so this closes the page itself — not just the menu entry. The
 * screen-access check runs first; Filament's own check (`parent::canAccess`)
 * still runs after it, so this can only ever narrow access, never widen it.
 *
 * It exists because hiding a resource is not the same as closing it. Before this,
 * a user with no role got 200 on the vendor create and edit pages and on the
 * approval-matrix create page — Filament's page defaults allow any signed-in user
 * when no policy says otherwise.
 */
trait AuthorizesResourcePage
{
    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(ScreenAccess::class)->allows($user, static::getResource())) {
            return false;
        }

        return parent::canAccess($parameters);
    }
}
