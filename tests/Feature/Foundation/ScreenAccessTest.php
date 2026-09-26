<?php

use App\Domain\Access\ScreenAccess;
use App\Domain\Projects\ProjectRole;
use App\Filament\Pages\ProjectProfitAndLoss;
use App\Filament\Resources\Accomplishments\AccomplishmentsResource;
use App\Filament\Resources\ApprovalMatrices\ApprovalMatrixResource;
use App\Filament\Resources\Approvals\ApprovalResource;
use App\Filament\Resources\ApVouchers\ApVouchersResource;
use App\Filament\Resources\ArEscalations\ArEscalationsResource;
use App\Filament\Resources\BackCharges\BackChargesResource;
use App\Filament\Resources\Billings\BillingsResource;
use App\Filament\Resources\Budgets\BudgetsResource;
use App\Filament\Resources\CashAdvances\CashAdvancesResource;
use App\Filament\Resources\CloseOutChecklists\CloseOutChecklistsResource;
use App\Filament\Resources\Contracts\ContractsResource;
use App\Filament\Resources\CostCodes\CostCodesResource;
use App\Filament\Resources\DailyTimeRecords\DailyTimeRecordsResource;
use App\Filament\Resources\Demobilizations\DemobilizationsResource;
use App\Filament\Resources\DisbursementBatches\DisbursementBatchesResource;
use App\Filament\Resources\Employees\EmployeesResource;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Expenses\ExpensesResource;
use App\Filament\Resources\FinalAccounts\FinalAccountsResource;
use App\Filament\Resources\Ledger\LedgerResource;
use App\Filament\Resources\Mobilizations\MobilizationsResource;
use App\Filament\Resources\OpexPeriods\OpexPeriodsResource;
use App\Filament\Resources\OvertimeAuthorities\OvertimeAuthoritiesResource;
use App\Filament\Resources\PayrollDeductions\PayrollDeductionsResource;
use App\Filament\Resources\PayrollRuns\PayrollRunsResource;
use App\Filament\Resources\Permits\PermitsResource;
use App\Filament\Resources\Projects\ProjectsResource;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrdersResource;
use App\Filament\Resources\PurchaseRequisitions\PurchaseRequisitionResource;
use App\Filament\Resources\ReceivingReports\ReceivingReportsResource;
use App\Filament\Resources\RetentionReleases\RetentionReleasesResource;
use App\Filament\Resources\Rfqs\RfqsResource;
use App\Filament\Resources\SalesInvoices\SalesInvoicesResource;
use App\Filament\Resources\StockCards\StockCardsResource;
use App\Filament\Resources\Subcontracts\SubcontractsResource;
use App\Filament\Resources\ThreeWayMatches\ThreeWayMatchesResource;
use App\Filament\Resources\TurnoverPacks\TurnoverPacksResource;
use App\Filament\Resources\Users\UsersResource;
use App\Filament\Resources\VendorAdvances\VendorAdvancesResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Resources\VendorScorecards\VendorScorecardResource;
use App\Filament\Resources\Warranties\WarrantiesResource;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Screen access by role
|--------------------------------------------------------------------------
|
| Until this, any signed-in user could open all 37 screens — a site foreman could
| read every payroll run and payslip, and anybody could open the approval-matrix
| admin. P6-01 limited WHICH PROJECTS a person sees; nothing limited WHICH
| SCREENS. P0-14 said per-screen authorisation would arrive with Phase 6. It did
| not, until now.
|
| **Enforced on the screen, not hidden from the menu.** Filament's own default
| for a custom page is "allow every authenticated user", and with no policies its
| create/edit/view checks fall through to allow as well. So every assertion here
| requests the URL directly; a screen that vanished from the navigation but still
| answered its URL would pass a menu test and fail this one.
|
| **Fail closed.** A screen with no rule is admin-only, and a test asserts every
| screen in the panel HAS a rule — so a new screen can neither slip in open nor
| lock everybody out without somebody noticing.
|
| **The map is configuration.** Part D item 5 has not named who owns each step;
| these roles are placeholders, and the client's answer is an edit to
| config/access.php, which one test proves.
|
*/

/** Every resource in the panel, by class. */
function allResourceScreens(): array
{
    return [
        AccomplishmentsResource::class, ApprovalMatrixResource::class, ApprovalResource::class, ApVouchersResource::class,
        ArEscalationsResource::class, BackChargesResource::class, BillingsResource::class, CashAdvancesResource::class,
        CloseOutChecklistsResource::class, DailyTimeRecordsResource::class, DemobilizationsResource::class,
        DisbursementBatchesResource::class, EmployeesResource::class, EquipmentResource::class, ExpensesResource::class,
        FinalAccountsResource::class, LedgerResource::class, MobilizationsResource::class, OpexPeriodsResource::class,
        OvertimeAuthoritiesResource::class, PayrollDeductionsResource::class, PayrollRunsResource::class,
        PermitsResource::class, PunchlistsResource::class, PurchaseOrdersResource::class, PurchaseRequisitionResource::class,
        ReceivingReportsResource::class, RetentionReleasesResource::class, RfqsResource::class, SalesInvoicesResource::class,
        StockCardsResource::class, ThreeWayMatchesResource::class, TurnoverPacksResource::class, VendorResource::class,
        VendorScorecardResource::class, WarrantiesResource::class,
        ProjectsResource::class, CostCodesResource::class, BudgetsResource::class, UsersResource::class, VendorAdvancesResource::class, ContractsResource::class, SubcontractsResource::class,
    ];
}

/*
|--------------------------------------------------------------------------
| Coverage and fail-closed
|--------------------------------------------------------------------------
*/

it('has an access rule for every screen registered in the panel', function () {
    // Checked against what Filament actually registers, not against a list kept
    // by hand, so a screen added next month without a rule fails here.
    $panel = Filament::getPanel('admin');
    $unruled = collect([...$panel->getResources(), ...$panel->getPages()])
        ->reject(fn (string $screen): bool => app(ScreenAccess::class)->hasRule($screen))
        ->values()
        ->all();

    expect($unruled)->toBe([]);
});

it('has an access rule for every dashboard widget', function () {
    // A widget is a screen. Without a rule ScreenAccess fails closed to admin
    // only, which is safe but silently hides the dashboard from everybody who
    // should have it — so the omission has to fail here instead.
    $unruled = collect(Filament::getPanel('admin')->getWidgets())
        ->map(fn ($widget): string => is_string($widget) ? $widget : $widget::class)
        ->reject(fn (string $widget): bool => str_starts_with($widget, 'Filament'))
        ->reject(fn (string $widget): bool => app(ScreenAccess::class)->hasRule($widget))
        ->values()
        ->all();

    expect($unruled)->toBe([]);
});

it('makes a screen with no rule admin-only rather than open', function () {
    $unknown = 'App\\Filament\\Resources\\NotYetMapped\\NotYetMappedResource';

    expect(app(ScreenAccess::class)->allows(userWithRole('finance-manager'), $unknown))->toBeFalse()
        ->and(app(ScreenAccess::class)->allows(userWithRole('admin'), $unknown))->toBeTrue();
});

it('lets the administrator open every screen', function (string $resource) {
    actingAs(panelUser());

    get($resource::getUrl('index'))->assertSuccessful();
})->with(allResourceScreens());

it('refuses a user with no role every screen but the approvals inbox', function (string $resource) {
    actingAs(User::factory()->create());

    $response = get($resource::getUrl('index'));

    $resource === ApprovalResource::class ? $response->assertSuccessful() : $response->assertForbidden();
})->with(allResourceScreens());

it('keeps the dashboard open to every signed-in user', function () {
    actingAs(User::factory()->create());

    get('/admin')->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Per role
|--------------------------------------------------------------------------
*/

it('gives HR the payroll screens and nothing financial', function () {
    actingAs(userWithRole('hr-manager'));

    foreach ([EmployeesResource::class, PayrollRunsResource::class, DailyTimeRecordsResource::class,
        DisbursementBatchesResource::class, OvertimeAuthoritiesResource::class, PayrollDeductionsResource::class] as $screen) {
        get($screen::getUrl('index'))->assertSuccessful();
    }

    foreach ([ApVouchersResource::class, BillingsResource::class, LedgerResource::class, ApprovalMatrixResource::class] as $screen) {
        get($screen::getUrl('index'))->assertForbidden();
    }
});

it('keeps salaries away from a project manager', function () {
    // The case this whole change is for: a site-side role reading payslips.
    actingAs(userWithRole('project-manager'));

    foreach ([EmployeesResource::class, PayrollRunsResource::class, DisbursementBatchesResource::class] as $screen) {
        get($screen::getUrl('index'))->assertForbidden();
    }

    foreach ([PurchaseRequisitionResource::class, PunchlistsResource::class, BillingsResource::class] as $screen) {
        get($screen::getUrl('index'))->assertSuccessful();
    }
});

it('gives finance payroll, payables, billing and reporting, but not the approval matrix', function () {
    actingAs(userWithRole('finance-manager'));

    foreach ([PayrollRunsResource::class, ApVouchersResource::class, BillingsResource::class, LedgerResource::class] as $screen) {
        get($screen::getUrl('index'))->assertSuccessful();
    }

    get(ProjectProfitAndLoss::getUrl())->assertSuccessful();
    get(ApprovalMatrixResource::getUrl('index'))->assertForbidden();
});

it('gives procurement its screens and keeps it out of payroll and billing', function () {
    actingAs(userWithRole('procurement-head'));

    foreach ([VendorResource::class, PurchaseOrdersResource::class, ReceivingReportsResource::class] as $screen) {
        get($screen::getUrl('index'))->assertSuccessful();
    }

    foreach ([PayrollRunsResource::class, BillingsResource::class] as $screen) {
        get($screen::getUrl('index'))->assertForbidden();
    }

    get(ProjectProfitAndLoss::getUrl())->assertForbidden();
});

it('gives the managing director reporting and billing', function () {
    actingAs(userWithRole('managing-director'));

    get(LedgerResource::getUrl('index'))->assertSuccessful();
    get(BillingsResource::getUrl('index'))->assertSuccessful();
    get(ProjectProfitAndLoss::getUrl())->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Direct URLs, not just the menu
|--------------------------------------------------------------------------
*/

it('refuses the create and edit pages of a screen the user cannot see', function () {
    // Filament's create/edit checks fall through to "allow" with no policies,
    // so hiding the list is not enough on its own.
    actingAs(User::factory()->create());
    $vendor = Vendor::factory()->create();

    get(VendorResource::getUrl('create'))->assertForbidden();
    get(VendorResource::getUrl('edit', ['record' => $vendor]))->assertForbidden();
    get(ApprovalMatrixResource::getUrl('create'))->assertForbidden();
});

it('refuses the close-out report page by direct URL', function () {
    // A custom page — Filament's default for those is to allow everyone.
    Carbon::setTestNow('2027-07-01 09:00:00');
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    try {
        actingAs(userWithRole('hr-manager'));
        get(CloseOutChecklistsResource::getUrl('view', ['record' => $checklist->getKey()]))->assertForbidden();

        // Assigned to the project as well as holding the role. The role opens the
        // screen; P6-01's project scope still decides which projects' data it
        // shows, and an unassigned project manager cannot see this project at
        // all. The first draft omitted the assignment and got 404 — the scope
        // working, not the screen rule failing.
        $pm = assignedTo($r['project'], ProjectRole::ProjectManager);
        $pm->assignRole(Role::findOrCreate('project-manager'));

        actingAs($pm->fresh());
        get(CloseOutChecklistsResource::getUrl('view', ['record' => $checklist->getKey()]))->assertSuccessful();
    } finally {
        Carbon::setTestNow();
    }
});

it('hides a screen from the navigation for a role that cannot open it', function () {
    actingAs(userWithRole('project-manager'));

    expect(EmployeesResource::canViewAny())->toBeFalse()
        ->and(PurchaseOrdersResource::canViewAny())->toBeTrue();
});

it('takes its roles from configuration, so the client can change them without code', function () {
    // Part D item 5 is unanswered; the answer should be an edit, not a release.
    config()->set('access.groups.Payroll', ['project-manager']);

    actingAs(userWithRole('project-manager'));

    get(EmployeesResource::getUrl('index'))->assertSuccessful();
});
