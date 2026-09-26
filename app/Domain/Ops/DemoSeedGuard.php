<?php

namespace App\Domain\Ops;

use DomainException;

/**
 * Where demo content may be seeded, and with what password — PLAN.md §7.
 *
 * §7: "Reset with `php artisan migrate:fresh --seed` against the staging
 * database only — guarded by an environment check so it can never point at
 * production." Two seeder docblocks promised this to Phase 6 and it was never
 * built. Laravel's own confirmation on `migrate:fresh` in production is skipped
 * by `--force`, so the guard lives in the seeders, where no flag reaches it.
 *
 * **The worse finding beside it.** The seeders created `admin@construction.test`
 * — and an account for every role the authority matrix routed a demo document
 * to — with the password `password`, in every environment. On production that is
 * a default-credential backdoor into the role that reads across every project.
 * On staging it is the same thing on a public subdomain that the staging template
 * says carries realistic data under production's rules.
 *
 * So: production is refused outright. Every other environment except local and
 * testing must be given a password, and may not be given the default. Local and
 * testing keep the default, because the browser console gate signs in with it
 * and nothing there is reachable from outside the machine.
 */
class DemoSeedGuard
{
    public const DEFAULT_PASSWORD = 'password';

    /**
     * Environments where the default password is acceptable. Named rather than
     * derived, so an environment somebody invents later — `uat`, `demo` — is
     * treated like staging by default rather than like local.
     */
    private const OPEN_ENVIRONMENTS = ['local', 'testing'];

    /**
     * @throws DomainException in production
     */
    public function assertMaySeed(string $environment): void
    {
        if ($environment === 'production') {
            throw new DomainException(
                'Refusing to seed demo content in production. PLAN.md §7 allows the demo reset against staging only, and these seeders create demo accounts and documents. '
                .'Seed the authority matrix on its own with `db:seed --class=ApprovalMatrixSeeder` if that is what is needed.'
            );
        }
    }

    /**
     * The password every seeded demo account is given.
     *
     * @throws DomainException when a public environment has no password, or has
     *                         been given the default
     */
    public function adminPassword(string $environment, ?string $configured): string
    {
        $this->assertMaySeed($environment);

        $configured = $configured === null ? '' : trim($configured);

        if (in_array($environment, self::OPEN_ENVIRONMENTS, true)) {
            return $configured === '' ? self::DEFAULT_PASSWORD : $configured;
        }

        if ($configured === '') {
            throw new DomainException(sprintf(
                'Refusing to create demo accounts in "%s" without a password. Set DEMO_ADMIN_PASSWORD: a known password here is a known password on the internet.',
                $environment,
            ));
        }

        if ($configured === self::DEFAULT_PASSWORD) {
            throw new DomainException(sprintf(
                'Refusing to create demo accounts in "%s" with the default password. DEMO_ADMIN_PASSWORD must be something other than "%s".',
                $environment,
                self::DEFAULT_PASSWORD,
            ));
        }

        return $configured;
    }
}
