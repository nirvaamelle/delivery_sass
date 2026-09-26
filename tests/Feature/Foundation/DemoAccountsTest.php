<?php

use App\Filament\Resources\ApprovalMatrices\ApprovalMatrixResource;
use App\Filament\Resources\Billings\BillingsResource;
use App\Filament\Resources\Employees\EmployeesResource;
use App\Filament\Resources\Ledger\LedgerResource;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrdersResource;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Demo accounts — one sign-in per role
|--------------------------------------------------------------------------
|
| Per-screen access (P6-01a) made each role see a different system, and the demo
| seed only created the three accounts its sample documents happened to need:
| admin, project manager, procurement head. Nobody could sign in as HR, finance
| or the managing director to see what those roles actually get.
|
| And the project manager that did exist saw an EMPTY system. P6-01 hides every
| project a user is not assigned to, and nothing assigned them to the demo
| project. So this seeds one account per role, and puts the project manager on
| the project they manage.
|
| Passwords come from DemoSeedGuard like every other seeded account: `password`
| locally, refused on staging (DemoSeedGuardTest checks every seeded user).
|
*/

beforeEach(function () {
    // DatabaseSeeder writes the admin's TOTP secret to the local disk in
    // testing; the real file belongs to the browser console gate.
    Storage::fake('local');

    artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
});

/** The account expected for each role. */
function demoAccountEmails(): array
{
    return [
        'admin' => 'admin@construction.test',
        'finance-manager' => 'finance.manager@construction.test',
        'managing-director' => 'managing.director@construction.test',
        'procurement-head' => 'procurement.head@construction.test',
        'project-manager' => 'project.manager@construction.test',
        'hr-manager' => 'hr.manager@construction.test',
    ];
}

function demoAccount(string $role): User
{
    return User::query()->where('email', demoAccountEmails()[$role])->sole();
}

it('seeds exactly one account for every role', function (string $role) {
    $holders = User::role($role)->get();

    expect($holders)->toHaveCount(1)
        ->and($holders->sole()->email)->toBe(demoAccountEmails()[$role]);
})->with(array_keys(demoAccountEmails()));

it('gives every demo account the local default password', function (string $role) {
    expect(Hash::check('password', demoAccount($role)->password))->toBeTrue();
})->with(array_keys(demoAccountEmails()));

it('does not duplicate accounts when seeded twice', function () {
    $before = User::query()->count();

    // The demo documents are not idempotent, so only the accounts seeder is
    // re-run — which is the case that matters: adding it to an existing database.
    artisan('db:seed', ['--class' => DemoAccountsSeeder::class, '--force' => true])->assertExitCode(0);

    expect(User::query()->count())->toBe($before);
});

it('puts the project manager on the demo project, so their screens are not empty', function () {
    // The finding: without an assignment, P6-01's scope hid every project from
    // the seeded project manager.
    actingAs(demoAccount('project-manager'));

    expect(Project::query()->pluck('code')->all())->toBe(['MBI-2026-014']);
});

it('shows the project manager the demo punchlist screen with the project on it', function () {
    actingAs(demoAccount('project-manager'));

    get(PunchlistsResource::getUrl('index'))->assertSuccessful();
    get(BillingsResource::getUrl('index'))->assertSuccessful();
});

it('lets each demo account open what its role is for, and not payroll unless it is HR or finance', function (string $role, string $allowed, string $refused) {
    // One account per test, not four in one. Switching users inside a single
    // test carries the previous user's password hash in the session, and the
    // panel's AuthenticateSession middleware then logs the new user out — a
    // 500 that no real user can hit, since each signs in through their own
    // session. The first draft did exactly that.
    actingAs(demoAccount($role));

    get($allowed::getUrl('index'))->assertSuccessful();
    get($refused::getUrl('index'))->assertForbidden();
})->with([
    'hr manager' => ['hr-manager', EmployeesResource::class, LedgerResource::class],
    // Finance is in every screen group in config/access.php, so the one screen
    // it is refused is the approval matrix, which is admin-only. The first draft
    // used purchase orders here, which finance is allowed.
    'finance manager' => ['finance-manager', LedgerResource::class, ApprovalMatrixResource::class],
    'managing director' => ['managing-director', LedgerResource::class, EmployeesResource::class],
    'procurement head' => ['procurement-head', PurchaseOrdersResource::class, EmployeesResource::class],
]);

it('lets the finance manager open payroll as well as the ledger', function () {
    // Finance is the one role that spans both, so it is asserted on its own.
    actingAs(demoAccount('finance-manager'));

    get(EmployeesResource::getUrl('index'))->assertSuccessful();
});
