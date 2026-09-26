<?php

namespace Database\Seeders;

use App\Domain\Ops\DemoSeedGuard;
use App\Domain\Security\TwoFactorService;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Keeps a usable Filament login after `migrate:fresh --seed`, which the
     * build loop runs as a verification step every iteration. Refused in
     * production by DemoSeedGuard (P6-08) — the guard PHASE-PLAN.md Phase 6
     * promised here and which, until then, existed only in this comment.
     */
    public function run(): void
    {
        /*
         * PLAN.md §7: the demo reset "can never point at production". First line,
         * before a single row exists — `migrate:fresh --force` skips Laravel's own
         * production confirmation, so this is the check no flag reaches.
         *
         * And the password comes from the guard, not a literal. Outside local and
         * testing a default-password admin is a backdoor into the role that reads
         * across every project.
         */
        $guard = app(DemoSeedGuard::class);
        $environment = (string) app()->environment();

        $guard->assertMaySeed($environment);
        $password = $guard->adminPassword($environment, config('ops.demo_admin_password'));

        $admin = User::updateOrCreate(
            ['email' => 'admin@construction.test'],
            [
                'name' => 'Local Admin',
                'password' => Hash::make($password),
            ],
        );

        // P6-01: every project-bearing query is scoped to the signed-in user's
        // assignments, and a user with none sees nothing. The local admin holds
        // the role that reads across projects — without it, `migrate:fresh
        // --seed` would leave a login that can see none of what it just seeded.
        $admin->assignRole(Role::findOrCreate('admin'));

        /*
         * P6-05 enforces a second factor on the admin role, so the local login
         * has to be enrolled or `migrate:fresh --seed` leaves an account that
         * is redirected to the setup page and can never reach the panel it just
         * seeded.
         *
         * Enrolled here rather than exempted: an exemption would be a hole in
         * exit gate clause 4 that starts life in the seeder and is still there
         * on the day somebody runs it against staging. The secret is generated
         * fresh each seed and never printed, so it is useless to anybody — the
         * point is only that the account counts as enrolled locally.
         */
        $twoFactor = app(TwoFactorService::class);

        if (! $twoFactor->isConfirmed($admin->fresh())) {
            $secret = $twoFactor->enable($admin);

            $admin->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => now(),
            ])->save();

            /*
             * The browser console gate signs in as this account and has to
             * answer P6-05a's challenge, so it needs the secret to compute a
             * code — the same thing a phone does, rather than a bypass.
             *
             * Local and testing ONLY, and guarded rather than trusted: a file
             * holding an administrator's TOTP secret is exactly what must not
             * exist on a server, and `.gitignore` keeps it out of the repo.
             */
            if (app()->environment('local', 'testing')) {
                Storage::disk('local')->put('dev-2fa-secret.txt', $secret);
            }
        }

        $this->call(ApprovalMatrixSeeder::class);
        $this->call(DemoVendorSeeder::class);
        $this->call(DemoChainSeeder::class);

        // After the demo chain, which creates the project the project manager is
        // assigned to. One sign-in per role, so each role's screens can be seen.
        $this->call(DemoAccountsSeeder::class);
    }
}
