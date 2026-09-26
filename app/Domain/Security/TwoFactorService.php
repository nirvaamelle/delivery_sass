<?php

namespace App\Domain\Security;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor authentication — P6-05, and Phase 6's exit gate clause 4.
 *
 * The clause: "2FA is **enforced** on every Finance, HR and Admin account."
 *
 * **Enforced, not offered.** An option nobody has to take is taken by nobody,
 * and the accounts the clause names are the ones that can move money, read
 * salaries and grant access. So a user holding one of those roles is stopped at
 * the panel door until they have enrolled — not warned, not nagged.
 *
 * **The secret is encrypted at rest**, under the rule PLAN.md §3 already
 * applies to vendor bank details and employee government numbers. A TOTP secret
 * in plaintext is a second factor that anybody holding a database dump holds
 * too, which is to say not a second factor.
 *
 * **Enabling is not confirming.** A secret generated but never verified against
 * a real code means somebody scanned a QR and closed the tab. Enforcing on the
 * secret alone would lock them out of the system with a factor they never
 * proved they can produce, on the day the rule turns on.
 *
 * **A used code cannot be replayed.** A TOTP window is thirty seconds wide, and
 * a code read over somebody's shoulder is good for the rest of it unless the
 * last one accepted is remembered. Bound as a singleton so that memory is one
 * memory rather than one per resolution.
 */
class TwoFactorService
{
    public function __construct(
        private readonly Google2FA $google,
    ) {}

    /**
     * Roles the gate requires a second factor of.
     *
     * From the exit gate's own words — Finance, HR and Admin. Deliberately not
     * widened: enforcing beyond the clause would lock a timekeeper out of the
     * system over a rule nobody agreed to, and this build does not invent
     * policy the client has not been asked for.
     *
     * @return array<int, string>
     */
    public function requiredRoles(): array
    {
        /** @var array<int, string> $roles */
        $roles = config('security.two_factor_roles', []);

        return $roles;
    }

    public function isRequiredFor(User $user): bool
    {
        foreach ($this->requiredRoles() as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start enrolment. Returns the secret to show as a QR code, once.
     */
    public function enable(User $user): string
    {
        $secret = $this->google->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_last_code' => null,
        ])->save();

        return $secret;
    }

    /**
     * Finish enrolment by proving the authenticator works.
     *
     * @throws DomainException when nothing was enabled, or the code is wrong
     */
    public function confirm(User $user, string $code): void
    {
        $secret = $this->secretFor($user);

        if (! $this->google->verifyKey($secret, $code)) {
            throw new DomainException('That code is not valid. Check the clock on the device generating it.');
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_code' => $code,
        ])->save();
    }

    /**
     * Check a code at sign-in.
     *
     * @throws DomainException when the code is wrong or has already been used
     */
    public function verify(User $user, string $code): bool
    {
        $secret = $this->secretFor($user);

        if ($user->two_factor_last_code === $code) {
            // The replay guard. Within one thirty-second window the same code
            // verifies twice, and the second time is somebody who read it off a
            // screen rather than a device.
            throw new DomainException('That code has already been used. Wait for the next one.');
        }

        if (! $this->google->verifyKey($secret, $code)) {
            throw new DomainException('That code is not valid.');
        }

        $user->forceFill(['two_factor_last_code' => $code])->save();

        return true;
    }

    public function isConfirmed(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null;
    }

    /**
     * Turn it off.
     *
     * @throws DomainException when the gate requires it of this account
     */
    public function disable(User $user): void
    {
        if ($this->isRequiredFor($user)) {
            // How clause 4 stops being true quietly, three months after
            // go-live: not by anybody deciding to switch it off, but by one
            // person finding it inconvenient.
            throw new DomainException(sprintf(
                'Two-factor authentication is required for %s and cannot be turned off. Remove the role first if that is really the intention.',
                implode(' / ', $this->requiredRoles()),
            ));
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_code' => null,
        ])->save();
    }

    /**
     * Accounts the gate covers that have not enrolled.
     *
     * Clause 4 is evidence somebody produces at go-live. "We turned it on" is
     * not evidence; this list is, and an empty one is the clause being met.
     *
     * @return Collection<int, User>
     */
    public function outstanding(): Collection
    {
        return User::query()
            ->whereNull('two_factor_confirmed_at')
            ->get()
            ->filter(fn (User $user): bool => $this->isRequiredFor($user))
            ->values();
    }

    /**
     * @throws DomainException
     */
    private function secretFor(User $user): string
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            throw new DomainException('Two-factor authentication has not been set up on this account.');
        }

        return $secret;
    }
}
