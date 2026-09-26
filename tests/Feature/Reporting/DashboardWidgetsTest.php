<?php

use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectRole;
use App\Domain\Reporting\KpiService;
use App\Filament\Widgets\BudgetVersusActualChart;
use App\Filament\Widgets\CashPosition;
use App\Filament\Widgets\NeedsAttention;
use App\Filament\Widgets\PortfolioOverview;
use App\Filament\Widgets\SCurveChart;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| The dashboard's widgets
|--------------------------------------------------------------------------
|
| A widget is a screen, and the same rule applies: who may see it is decided by
| config/access.php, not by the fact that it is a summary. Company margin on a
| foreman's home page is the same disclosure as the P&L screen they are refused.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-06-15 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('renders every widget for finance', function (string $widget) {
    actingAs(userWithRole('finance-manager'));

    Livewire::test($widget)->assertOk();
})->with([
    NeedsAttention::class,
    PortfolioOverview::class,
    CashPosition::class,
    SCurveChart::class,
    BudgetVersusActualChart::class,
]);

it('refuses the whole dashboard to a site role', function (string $widget) {
    // A foreman's home page shows the approvals inbox and nothing financial.
    actingAs(userWithRole('foreman'));

    expect($widget::canView())->toBeFalse();
})->with([
    NeedsAttention::class,
    PortfolioOverview::class,
    CashPosition::class,
    SCurveChart::class,
    BudgetVersusActualChart::class,
]);

it('gives a project manager the project widgets but not the cash position', function () {
    // What the company owes and is owed across every project is finance's.
    actingAs(userWithRole('project-manager'));

    expect(PortfolioOverview::canView())->toBeTrue()
        ->and(SCurveChart::canView())->toBeTrue()
        ->and(BudgetVersusActualChart::canView())->toBeTrue()
        ->and(CashPosition::canView())->toBeFalse();
});

it('says so when nothing needs attention, rather than showing an empty panel', function () {
    // A panel that vanishes when empty teaches people its absence means nothing
    // was checked.
    actingAs(userWithRole('finance-manager'));

    Livewire::test(NeedsAttention::class)->assertSee('Nothing needs attention');
});

it('shows a project manager their own project total, not the company\'s', function () {
    $mine = Project::factory()->create();
    Project::factory()->create();

    $pm = userWithRole('project-manager');
    app(ProjectAccessService::class)->assign($pm, $mine, ProjectRole::ProjectManager);

    actingAs($pm);

    // One active project in view, not two.
    Livewire::test(PortfolioOverview::class)->assertSee('Active projects');

    expect(app(KpiService::class)->portfolio()['active_projects'])->toBe(1);
});

it('reads "not yet billed" rather than a zero margin on an empty system', function () {
    actingAs(userWithRole('finance-manager'));

    Livewire::test(PortfolioOverview::class)->assertSee('Not yet billed');
});
