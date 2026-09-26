<?php

use App\Filament\Resources\Billings\BillingsResource;
use App\Filament\Resources\DailyTimeRecords\DailyTimeRecordsResource;
use App\Filament\Resources\Employees\EmployeesResource;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Ledger\LedgerResource;
use App\Filament\Resources\Mobilizations\MobilizationsResource;
use App\Filament\Resources\OvertimeAuthorities\OvertimeAuthoritiesResource;
use App\Filament\Resources\PayrollRuns\PayrollRunsResource;
use App\Filament\Resources\Permits\PermitsResource;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrdersResource;
use App\Filament\Resources\PurchaseRequisitions\PurchaseRequisitionResource;
use App\Filament\Resources\ReceivingReports\ReceivingReportsResource;
use App\Filament\Resources\StockCards\StockCardsResource;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Site staff access
|--------------------------------------------------------------------------
|
| Per-screen access (P6-01a) gave office roles their screens and left the site
| roles with nothing but the dashboard and the approvals inbox. A timekeeper
| could not open the DTR screen they exist to keep; a storekeeper could not see
| a receiving report.
|
| Each site role now gets the screens its job is, and nothing it is not: no
| payroll run, no payslip, no billing, no ledger. The rules stay in
| config/access.php as per-screen entries, so a site role's reach is readable in
| one place rather than inferred from which groups it happens to be in.
|
| PLACEHOLDER: Part D item 5 — the client has not said what each site role does.
| These follow the job titles.
|
*/

/**
 * @return array<string, array{0: string, 1: array<int, class-string>, 2: array<int, class-string>}>
 */
function siteStaffReach(): array
{
    return [
        'timekeeper' => ['timekeeper',
            [DailyTimeRecordsResource::class, OvertimeAuthoritiesResource::class],
            [PayrollRunsResource::class, EmployeesResource::class, BillingsResource::class]],

        'foreman' => ['foreman',
            [DailyTimeRecordsResource::class, OvertimeAuthoritiesResource::class, PunchlistsResource::class],
            [PayrollRunsResource::class, EmployeesResource::class, LedgerResource::class]],

        'storekeeper' => ['storekeeper',
            [ReceivingReportsResource::class, StockCardsResource::class, EquipmentResource::class, PurchaseOrdersResource::class],
            [PayrollRunsResource::class, BillingsResource::class, LedgerResource::class]],

        'site engineer' => ['site-engineer',
            [PurchaseRequisitionResource::class, DailyTimeRecordsResource::class, OvertimeAuthoritiesResource::class,
                PunchlistsResource::class, MobilizationsResource::class, PermitsResource::class, ReceivingReportsResource::class],
            [PayrollRunsResource::class, EmployeesResource::class, LedgerResource::class, BillingsResource::class]],
    ];
}

it('opens the screens a site role works in', function (string $role, array $allowed) {
    actingAs(userWithRole($role));

    foreach ($allowed as $screen) {
        get($screen::getUrl('index'))->assertSuccessful();
    }
})->with(siteStaffReach());

it('refuses a site role payroll, billing and the ledger', function (string $role, array $allowed, array $refused) {
    actingAs(userWithRole($role));

    foreach ($refused as $screen) {
        get($screen::getUrl('index'))->assertForbidden();
    }
})->with(siteStaffReach());

it('leaves every other role exactly as it was on the DTR screen', function () {
    // Adding site roles to a screen must not take it away from HR and finance.
    actingAs(userWithRole('hr-manager'));
    get(DailyTimeRecordsResource::getUrl('index'))->assertSuccessful();

    actingAs(userWithRole('project-manager'));
    get(DailyTimeRecordsResource::getUrl('index'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Demo accounts
|--------------------------------------------------------------------------
*/

function seedDemoForSiteStaff(): void
{
    // DatabaseSeeder writes the admin's TOTP secret to the local disk in testing;
    // the real file belongs to the browser console gate.
    Storage::fake('local');

    artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
}

it('seeds one demo account per site role, assigned to the demo project', function (string $role, string $email) {
    seedDemoForSiteStaff();

    $user = User::query()->where('email', $email)->sole();

    actingAs($user);

    expect($user->hasRole($role))->toBeTrue()
        ->and(Project::query()->pluck('code')->all())->toBe(['MBI-2026-014']);
})->with([
    'timekeeper' => ['timekeeper', 'timekeeper@construction.test'],
    'foreman' => ['foreman', 'foreman@construction.test'],
    'storekeeper' => ['storekeeper', 'storekeeper@construction.test'],
    'site engineer' => ['site-engineer', 'site.engineer@construction.test'],
]);

it('shows the demo foreman the demo project punchlist screen', function () {
    seedDemoForSiteStaff();

    actingAs(User::query()->where('email', 'foreman@construction.test')->sole());

    get(PunchlistsResource::getUrl('index'))->assertSuccessful();
});
