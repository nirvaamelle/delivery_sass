<?php

namespace App\Domain\Access;

use App\Models\User;

/**
 * Who may open which screen — the per-screen half of access control.
 *
 * P6-01's project scope decides which PROJECTS a user's queries return. Nothing
 * decided which SCREENS they could open, so any signed-in user could read every
 * payroll run and open the approval-matrix admin. This is that decision, and it
 * lives in the domain for the reason every control in this build does: the
 * Filament screens call it, they do not contain it.
 *
 * **Fail closed.** A screen with no rule is administrator-only. The opposite
 * default would open every screen somebody forgets to map, and the screens most
 * likely to be forgotten are the newest ones.
 *
 * The rules are configuration (`config/access.php`), because Part D item 5 has
 * not named who owns each step, and the client's answer should be an edit.
 */
class ScreenAccess
{
    /** Marks a screen open to every signed-in user. */
    public const EVERYONE = '*';

    /**
     * May this user open this screen?
     *
     * @param  string  $screen  a Filament resource or page class
     */
    public function allows(User $user, string $screen): bool
    {
        foreach ($this->stringList(config('access.super_roles', [])) as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        $roles = $this->rolesFor($screen);

        if ($roles === null) {
            return false;
        }

        if ($roles === self::EVERYONE) {
            return true;
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this screen have any rule at all? A screen without one is admin-only,
     * and the coverage test uses this to catch it.
     */
    public function hasRule(string $screen): bool
    {
        return $this->rolesFor($screen) !== null;
    }

    /**
     * The roles allowed onto a screen, `'*'` for everyone, or null for no rule.
     *
     * A rule for the specific screen wins over its navigation group's, so one
     * screen filed under a misleading group can be corrected without moving it.
     *
     * @return array<int, string>|string|null
     */
    public function rolesFor(string $screen): array|string|null
    {
        $screens = config('access.screens', []);

        if (is_array($screens) && array_key_exists($screen, $screens)) {
            return $this->normalise($screens[$screen]);
        }

        $group = $this->groupOf($screen);
        $groups = config('access.groups', []);

        if ($group !== null && is_array($groups) && array_key_exists($group, $groups)) {
            return $this->normalise($groups[$group]);
        }

        return null;
    }

    /**
     * @return array<int, string>|string
     */
    private function normalise(mixed $rule): array|string
    {
        return $rule === self::EVERYONE ? self::EVERYONE : $this->stringList($rule);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $role): bool => is_string($role) && $role !== ''));
    }

    private function groupOf(string $screen): ?string
    {
        if (! class_exists($screen) || ! method_exists($screen, 'getNavigationGroup')) {
            return null;
        }

        $group = $screen::getNavigationGroup();

        return is_string($group) ? $group : null;
    }
}
