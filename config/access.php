<?php

use App\Filament\Pages\ProjectProfitAndLoss;
use App\Filament\Pages\ThirteenthMonthPay;
use App\Filament\Resources\ApprovalMatrices\ApprovalMatrixResource;
use App\Filament\Resources\Approvals\ApprovalResource;
use App\Filament\Resources\Budgets\BudgetsResource;
use App\Filament\Resources\Contracts\ContractsResource;
use App\Filament\Resources\DailyTimeRecords\DailyTimeRecordsResource;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Mobilizations\MobilizationsResource;
use App\Filament\Resources\OvertimeAuthorities\OvertimeAuthoritiesResource;
use App\Filament\Resources\PayrollDeductions\PayrollDeductionsResource;
use App\Filament\Resources\Permits\PermitsResource;
use App\Filament\Resources\Projects\ProjectsResource;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrdersResource;
use App\Filament\Resources\PurchaseRequisitions\PurchaseRequisitionResource;
use App\Filament\Resources\ReceivingReports\ReceivingReportsResource;
use App\Filament\Resources\StockCards\StockCardsResource;
use App\Filament\Resources\Subcontracts\SubcontractsResource;
use App\Filament\Resources\Users\UsersResource;
use App\Filament\Resources\VendorAdvances\VendorAdvancesResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Resources\Warehouses\WarehousesResource;
use App\Filament\Widgets\BudgetVersusActualChart;
use App\Filament\Widgets\CashPosition;
use App\Filament\Widgets\NeedsAttention;
use App\Filament\Widgets\PortfolioOverview;
use App\Filament\Widgets\SCurveChart;
use Filament\Pages\Dashboard;

/*
|--------------------------------------------------------------------------
| Who may open which screen
|--------------------------------------------------------------------------
|
| Until this existed, every signed-in user could open every screen — a site
| foreman could read every payroll run. Project assignments (P6-01) decide which
| PROJECTS a person sees; this decides which SCREENS.
|
| PLACEHOLDER: Part D item 5 — the client has not named who owns each step. These
| roles follow the authority matrix the build already seeds, and changing who sees
| what is an edit to this file, not a release.
|
| Resolution, in order:
|   1. `super_roles` see everything.
|   2. `screens` — a rule for one specific screen, which wins over its group.
|   3. `groups` — by the screen's navigation group.
|   4. No rule at all → admin only. Fail closed: a new screen nobody mapped is
|      locked, not open. A test asserts every registered screen has a rule, so
|      that is noticed rather than discovered by a confused user.
|
*/

return [

    'super_roles' => ['admin'],

    'screens' => [
        // Open to every signed-in user. The inbox already filters to the
        // approvals addressed to your own roles, and the dashboard shows nothing.
        ApprovalResource::class => '*',
        Dashboard::class => '*',

        // Redefining approval authority is not a finance or procurement task.
        ApprovalMatrixResource::class => [],

        // Ungrouped in the menu; procurement's screen.
        VendorResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'managing-director'],

        // Sits under OPEX in the menu, but it is money taken from somebody's pay
        // (F17). HR has to see it; project managers do not.
        PayrollDeductionsResource::class => ['hr-manager', 'finance-manager'],

        ProjectProfitAndLoss::class => ['finance-manager', 'managing-director'],

        // Pay, so it follows the payroll screens.
        ThirteenthMonthPay::class => ['hr-manager', 'finance-manager'],

        /*
         * Dashboard widgets, which are screens like any other. A summary is not
         * exempt from access control because it is a summary: company margin on
         * a foreman's home page is the same disclosure as the P&L screen they
         * are refused. Each widget's figures are project-scoped, so a project
         * manager sees their own jobs added up rather than the company's.
         */
        NeedsAttention::class => ['project-manager', 'finance-manager', 'managing-director'],
        PortfolioOverview::class => ['project-manager', 'finance-manager', 'managing-director'],
        SCurveChart::class => ['project-manager', 'finance-manager', 'managing-director'],
        BudgetVersusActualChart::class => ['project-manager', 'finance-manager', 'managing-director'],

        // Cash is finance's: what the company owes and is owed across every
        // project is not a project manager's to read.
        CashPosition::class => ['finance-manager', 'managing-director'],

        /*
         * Site staff. Each entry REPLACES its group's rule for that one screen,
         * so it repeats the group's roles and adds the site roles on top. Site
         * roles are not unscoped (ProjectScope), so they still see only the
         * projects they are assigned to.
         *
         * PLACEHOLDER: Part D item 5 — what each site role does is not agreed;
         * these follow the job titles.
         */
        // Timekeeping: the timekeeper keeps it, the foreman and site engineer certify it.
        DailyTimeRecordsResource::class => ['hr-manager', 'finance-manager', 'timekeeper', 'foreman', 'site-engineer'],
        OvertimeAuthoritiesResource::class => ['hr-manager', 'finance-manager', 'timekeeper', 'foreman', 'site-engineer'],

        // Defects are walked and cleared on site.
        PunchlistsResource::class => ['project-manager', 'finance-manager', 'managing-director', 'foreman', 'site-engineer'],

        // The storekeeper receives against a PO, keeps the stock card and the plant.
        ReceivingReportsResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'storekeeper', 'site-engineer'],
        StockCardsResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'storekeeper'],

        // The facility register. Read by the same people who read stock, and
        // the screen every other logistics table resolves a warehouse against.
        WarehousesResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'storekeeper'],
        EquipmentResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'storekeeper'],
        PurchaseOrdersResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'managing-director', 'storekeeper'],

        // The site engineer raises requisitions and runs mobilization.
        PurchaseRequisitionResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'managing-director', 'site-engineer'],
        MobilizationsResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'managing-director', 'site-engineer'],
        PermitsResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'managing-director', 'site-engineer'],

        // Setup. A project manager keeps the projects and budgets of the jobs
        // they are on (rows are project-scoped); opening a project is limited
        // further, to roles that see every project (ProjectsResource::canCreate).
        ProjectsResource::class => ['project-manager', 'finance-manager', 'managing-director'],
        BudgetsResource::class => ['project-manager', 'finance-manager', 'managing-director'],

        // The document the whole chain rests on. A project manager reads the
        // contracts of their own jobs; drafting and signing is finance's.
        ContractsResource::class => ['project-manager', 'finance-manager', 'managing-director'],

        // Works let to a subcontractor: procurement awards them, the project
        // manager runs them, and finance nets back-charges off what is payable.
        SubcontractsResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'managing-director'],

        // Whoever edits roles decides what everybody else can open.
        UsersResource::class => [],

        // Money leaving before delivery: finance's, and the procurement head who
        // owns the order it is advanced against.
        VendorAdvancesResource::class => ['finance-manager', 'managing-director', 'procurement-head'],
    ],

    'groups' => [
        // Salaries and government numbers. Not project managers, not site roles.
        'Payroll' => ['hr-manager', 'finance-manager'],

        'Payables' => ['finance-manager', 'managing-director'],
        'Billing' => ['finance-manager', 'managing-director', 'project-manager'],
        'OPEX' => ['finance-manager', 'managing-director', 'project-manager'],
        'Reporting' => ['finance-manager', 'managing-director'],

        'Procurement' => ['procurement-head', 'project-manager', 'finance-manager', 'managing-director'],
        'Operations' => ['procurement-head', 'project-manager', 'finance-manager', 'managing-director'],
        'Warehouse' => ['procurement-head', 'project-manager', 'finance-manager'],
        'Assets' => ['procurement-head', 'project-manager', 'finance-manager'],

        'Close-out' => ['project-manager', 'finance-manager', 'managing-director'],

        // Master data: the cost code tree is company-wide, so finance's.
        'Setup' => ['finance-manager', 'managing-director'],
    ],

];
