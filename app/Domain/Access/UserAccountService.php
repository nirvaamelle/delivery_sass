<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Sign-in accounts — creating one, correcting it, and deciding its roles.
 *
 * Accounts were made by seeders and nothing else, so the client could not add
 * the people who will use the system. What an account opens is its roles
 * (config/access.php) and which projects it sees is its assignments (P6-01);
 * this service handles the first, ProjectAccessService the second.
 *
 * **Roles must already exist.** A typed role that matches nothing would grant
 * nothing and look like it granted something.
 *
 * **The last administrator cannot be demoted.** Removing it locks everybody out
 * of the one screen that can put it back.
 */
class UserAccountService
{
    /**
     * PLACEHOLDER: password policy is not a Part D item at all (recorded as
     * unnumbered in DECISIONS-PENDING.md). Twelve characters follows current
     * guidance for length over complexity rules.
     */
    public const MIN_PASSWORD_LENGTH = 12;

    /**
     * @param  array<int, string>  $roles
     */
    public function create(string $name, string $email, string $password, array $roles, ?User $by = null): User
    {
        $name = trim($name);
        $email = strtolower(trim($email));

        if ($name === '') {
            throw new InvalidAccountDetail('name', 'An account needs a name.');
        }

        $this->assertEmail($email);
        $this->assertPassword($password);
        $this->assertRolesExist($roles);

        return DB::transaction(function () use ($name, $email, $password, $roles, $by): User {
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->syncRoles($roles);

            activity()->performedOn($user)->causedBy($by)
                ->withProperties(['roles' => array_values($roles)])
                ->log('account-created');

            return $user;
        });
    }

    public function updateDetails(User $user, string $name, string $email, ?User $by = null): User
    {
        $name = trim($name);
        $email = strtolower(trim($email));

        if ($name === '') {
            throw new InvalidAccountDetail('name', 'An account needs a name.');
        }

        $this->assertEmail($email, $user);

        $user->fill(['name' => $name, 'email' => $email])->save();

        return $user;
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function setRoles(User $user, array $roles, ?User $by = null): User
    {
        $this->assertRolesExist($roles);

        if ($user->hasRole('admin') && ! in_array('admin', $roles, true) && $this->adminCount() <= 1) {
            throw new InvalidAccountDetail('roles', 'This is the last administrator. Make somebody else an administrator first.');
        }

        $before = $user->getRoleNames()->sort()->values()->all();
        $user->syncRoles($roles);

        activity()->performedOn($user)->causedBy($by)
            ->withProperties(['from' => $before, 'to' => collect($roles)->sort()->values()->all()])
            ->log('account-roles-changed');

        return $user;
    }

    public function setPassword(User $user, string $password, ?User $by = null): User
    {
        $this->assertPassword($password);

        $user->forceFill(['password' => $password])->save();

        // Never the password itself.
        activity()->performedOn($user)->causedBy($by)->log('account-password-set');

        return $user;
    }

    private function assertEmail(string $email, ?User $ignore = null): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidAccountDetail('email', 'That is not an email address.');
        }

        $taken = User::query()
            ->where('email', $email)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->exists();

        if ($taken) {
            throw new InvalidAccountDetail('email', sprintf('%s already has an account.', $email));
        }
    }

    private function assertPassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidAccountDetail('password', sprintf('A password needs at least %d characters.', self::MIN_PASSWORD_LENGTH));
        }
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function assertRolesExist(array $roles): void
    {
        $unknown = array_values(array_diff($roles, Role::query()->pluck('name')->all()));

        if ($unknown !== []) {
            throw new InvalidAccountDetail('roles', sprintf('There is no role called %s.', $unknown[0]));
        }
    }

    private function adminCount(): int
    {
        return User::role('admin')->count();
    }
}
