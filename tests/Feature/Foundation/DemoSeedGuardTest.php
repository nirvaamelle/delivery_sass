<?php

use App\Domain\Ops\DemoSeedGuard;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| The demo seed guard — P6-08 gap, PLAN.md §7
|--------------------------------------------------------------------------
|
| §7: "Reset with `php artisan migrate:fresh --seed` against the staging database
| only — guarded by an environment check so it can never point at production."
|
| Promised in two seeder docblocks ("PHASE-PLAN.md Phase 6 guards the demo seed
| against production") and never built. Laravel's own confirmation on
| `migrate:fresh` in production is skipped by `--force`, so the guard belongs in
| the seeder, where no flag reaches it.
|
| **And a worse finding beside it.** `DatabaseSeeder` creates
| `admin@construction.test` with the password `password` in EVERY environment —
| a default-credential backdoor into the one role that reads across every
| project. So the guard does two things:
|
|   - refuses to seed demo content in production at all;
|   - outside local and testing, refuses to create the admin unless
|     DEMO_ADMIN_PASSWORD is set, and refuses that password being `password`.
|
| **It is not only the admin.** `DemoChainSeeder::approver()` creates a user for
| every role the authority matrix routes a demo document to — each with
| `bcrypt('password')`, in every environment. Those are real, loginable accounts
| holding approval authority. So outside local and testing, no seeded account of
| any kind gets the default password.
|
| Local and testing keep the default, because the browser console gate signs in
| with it and nothing there is reachable from outside the machine.
|
*/

beforeEach(function () {
    // Faked, not optional. DatabaseSeeder writes dev-2fa-secret.txt in local and
    // testing, and the REAL file is the browser console gate's key to the DEV
    // database's admin. Seeding here against the real disk would overwrite it
    // with this test database's secret, and `npm run test:console` would then
    // fail its 2FA challenge with nothing in its own output pointing here.
    Storage::fake('local');
});

afterEach(fn () => app()['env'] = 'testing');

it('refuses to seed demo content in production', function () {
    expect(fn () => app(DemoSeedGuard::class)->assertMaySeed('production'))
        ->toThrow(DomainException::class, 'production');
});

it('refuses from the seeder itself before a single row is written', function () {
    // The guard at the point no --force flag reaches.
    //
    // Expected as a thrown refusal, not as exit code 1, and that is the
    // framework rather than a softened test: Laravel's console Application
    // disables exception catching, and Kernel::call — what artisan() uses — does
    // not catch either, so the exception reaches the test. On the real command
    // line Kernel::handle catches it and exits 1. What this still demands is the
    // part that matters: refused, and not one row written first.
    app()['env'] = 'production';
    $before = User::query()->count();

    expect(fn () => artisan('db:seed', ['--force' => true])->run())
        ->toThrow(DomainException::class, 'production');

    expect(User::query()->count())->toBe($before)
        ->and(User::query()->where('email', 'admin@construction.test')->exists())->toBeFalse();
});

it('allows local and testing, with the default password the browser gate uses', function (string $env) {
    expect(fn () => app(DemoSeedGuard::class)->assertMaySeed($env))->not->toThrow(DomainException::class)
        ->and(app(DemoSeedGuard::class)->adminPassword($env, null))->toBe('password');
})->with(['local', 'testing']);

it('refuses to create the staging admin without an explicit password', function () {
    // Staging is a public subdomain carrying realistic data under production's
    // rules. A known password there is a known password on the internet.
    expect(fn () => app(DemoSeedGuard::class)->adminPassword('staging', null))
        ->toThrow(DomainException::class, 'DEMO_ADMIN_PASSWORD');
});

it('refuses the default password even when it is set explicitly on staging', function () {
    expect(fn () => app(DemoSeedGuard::class)->adminPassword('staging', 'password'))
        ->toThrow(DomainException::class, 'default');
});

it('uses the configured password on staging, and the default does not open it', function () {
    app()['env'] = 'staging';
    config()->set('ops.demo_admin_password', 'correct-horse-battery-staple-7');

    artisan('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder', '--force' => true])->assertExitCode(0);

    $admin = User::query()->where('email', 'admin@construction.test')->sole();

    expect(Hash::check('password', $admin->password))->toBeFalse()
        ->and(Hash::check('correct-horse-battery-staple-7', $admin->password))->toBeTrue();
});

it('gives no seeded account the default password on staging, approvers included', function () {
    // The approver accounts are created by DemoChainSeeder, not DatabaseSeeder,
    // and hold real approval authority. Checking only the admin would leave
    // every one of them logging in with `password` on a public subdomain.
    app()['env'] = 'staging';
    config()->set('ops.demo_admin_password', 'correct-horse-battery-staple-7');

    artisan('db:seed', ['--force' => true])->assertExitCode(0);

    $defaults = User::query()->get()->filter(fn (User $user): bool => Hash::check('password', $user->password));

    expect($defaults->pluck('email')->all())->toBe([]);
});

it('keeps the default password for seeded approvers locally, where the demo is walked by hand', function () {
    artisan('db:seed', ['--force' => true])->assertExitCode(0);

    $approvers = User::query()->where('email', '!=', 'admin@construction.test')->get();

    expect($approvers)->not->toBeEmpty()
        ->and($approvers->every(fn (User $user): bool => Hash::check('password', $user->password)))->toBeTrue();
});

it('does not write the TOTP secret file on staging', function () {
    // P6-05a writes it for the local browser gate only. An administrator's second
    // factor sitting in a file on a public server is no second factor.
    app()['env'] = 'staging';
    config()->set('ops.demo_admin_password', 'correct-horse-battery-staple-7');

    artisan('db:seed', ['--force' => true])->assertExitCode(0);

    Storage::disk('local')->assertMissing('dev-2fa-secret.txt');
});
