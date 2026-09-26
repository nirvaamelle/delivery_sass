<?php

use App\Domain\Billing\AgingBucket;
use App\Domain\Billing\CollectionService;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\LedgerPoster;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectRole;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Reporting\KpiService;
use App\Models\CostCode;
use App\Models\CutoffCalendar;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| The KPI dashboard's figures
|--------------------------------------------------------------------------
|
| Every role signed in to a blank page. PLAN.md §2 names the widgets — S-curve,
| budget vs actual, cost per unit of accomplishment — and none existed.
|
| Two rules shape this service, and both are about not lying:
|
|   - **Every figure is summed in bcmath from the ledger**, never SQL SUM() over
|     a decimal cast, and never stored. A KPI that drifts from the ledger it
|     claims to summarise is worse than no KPI, because somebody will act on it.
|   - **It reads through the project scope.** A project manager's dashboard adds
|     up their own projects; it must not leak a company total through a headline
|     figure the screens themselves would refuse to show them.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-06-15 09:00:00');

    // A posting is judged by the calendar for its own chain, so the months the
    // fixtures post into have to be open.
    foreach ([CutoffType::Billing, CutoffType::Opex, CutoffType::Payroll] as $type) {
        foreach ([['2026-04-01', '2026-04-30'], ['2026-05-01', '2026-05-31'], ['2026-06-01', '2026-06-30']] as [$start, $end]) {
            CutoffCalendar::query()->create([
                'project_id' => null,
                'cutoff_type' => $type,
                'period_start' => $start,
                'period_end' => $end,
                'cutoff_at' => '2026-12-31 17:00:00',
            ]);
        }
    }
});

afterEach(fn () => Carbon::setTestNow());

function kpis(): KpiService
{
    return app(KpiService::class);
}

/**
 * A project with real postings behind it.
 */
function projectWithLedger(string $revenue, string $cost, ?Organization $organization = null): Project
{
    $organization = $organization ?? Organization::factory()->create();
    $project = Project::factory()->for($organization)->create(['status' => ProjectStatus::Active]);
    $costCode = CostCode::factory()->for($organization)->create();

    $poster = app(LedgerPoster::class);

    $poster->post(
        project: $project,
        costCode: $costCode,
        category: LedgerCategory::Revenue,
        amount: $revenue,
        sourceDocument: $project,
        documentNumber: 'REV-'.$project->code,
        cutoffType: CutoffType::Billing,
        documentDate: Carbon::parse('2026-06-01'),
        description: 'Revenue',
    );

    $poster->post(
        project: $project,
        costCode: $costCode,
        category: LedgerCategory::Material,
        amount: $cost,
        sourceDocument: $project,
        documentNumber: 'MAT-'.$project->code,
        cutoffType: CutoffType::Opex,
        documentDate: Carbon::parse('2026-06-02'),
        description: 'Material',
    );

    return $project->fresh();
}

/*
|--------------------------------------------------------------------------
| The headline figures
|--------------------------------------------------------------------------
*/

it('adds up revenue, cost and margin across the portfolio', function () {
    $organization = Organization::factory()->create();
    projectWithLedger('1000000.0000', '600000.0000', $organization);
    projectWithLedger('500000.0000', '400000.0000', $organization);

    $portfolio = kpis()->portfolio();

    expect($portfolio['revenue'])->toBe('1500000.0000')
        ->and($portfolio['cost'])->toBe('1000000.0000')
        ->and($portfolio['gross_profit'])->toBe('500000.0000')
        // 500,000 of 1,500,000.
        ->and($portfolio['margin_percent'])->toBe('33.33')
        ->and($portfolio['active_projects'])->toBe(2);
});

it('reports no margin rather than zero when nothing has been earned', function () {
    // Zero margin on zero revenue reads as "we worked for nothing", which is a
    // different and much worse statement than "nothing has been billed yet".
    $portfolio = kpis()->portfolio();

    expect($portfolio['revenue'])->toBe('0.0000')
        ->and($portfolio['margin_percent'])->toBeNull()
        ->and($portfolio['active_projects'])->toBe(0);
});

it('counts only active projects, not closed ones', function () {
    $organization = Organization::factory()->create();
    projectWithLedger('1000000.0000', '600000.0000', $organization);
    Project::factory()->for($organization)->create(['status' => ProjectStatus::Closed]);
    Project::factory()->for($organization)->create(['status' => ProjectStatus::OnHold]);

    expect(kpis()->portfolio()['active_projects'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The scope, which is the part that could leak
|--------------------------------------------------------------------------
*/

it('shows a project manager only the projects they are on', function () {
    $organization = Organization::factory()->create();
    $mine = projectWithLedger('1000000.0000', '600000.0000', $organization);
    projectWithLedger('9000000.0000', '1000000.0000', $organization);

    $pm = userWithRole('project-manager');
    app(ProjectAccessService::class)->assign($pm, $mine, ProjectRole::ProjectManager);

    actingAs($pm);

    $portfolio = kpis()->portfolio();

    // Their own project only — not the 10,000,000 the company earned.
    expect($portfolio['revenue'])->toBe('1000000.0000')
        ->and($portfolio['active_projects'])->toBe(1);
});

it('shows finance the whole portfolio', function () {
    $organization = Organization::factory()->create();
    projectWithLedger('1000000.0000', '600000.0000', $organization);
    projectWithLedger('9000000.0000', '1000000.0000', $organization);

    actingAs(userWithRole('finance-manager'));

    expect(kpis()->portfolio()['revenue'])->toBe('10000000.0000');
});

/*
|--------------------------------------------------------------------------
| Cash
|--------------------------------------------------------------------------
*/

it('reports what is owed to the company and what it owes', function () {
    $organization = Organization::factory()->create();
    $project = projectWithLedger('1000000.0000', '600000.0000', $organization);

    $cash = kpis()->cashPosition();

    expect($cash)->toHaveKeys(['receivable', 'payable', 'retention_held', 'net'])
        ->and($cash['net'])->toBe(bcsub($cash['receivable'], $cash['payable'], 4));
});

/*
|--------------------------------------------------------------------------
| What needs attention
|--------------------------------------------------------------------------
*/

it('lists nothing when nothing is outstanding', function () {
    expect(kpis()->needsAttention())->toBe([]);
});

it('raises the receivable that has aged past ninety days', function () {
    // The one an executive should see without opening a screen.
    $billing = approvedBilling();
    app(CollectionService::class)->invoice($billing, Carbon::parse('2026-01-02'));

    $attention = collect(kpis()->needsAttention());

    expect($attention->pluck('key'))->toContain('ar_over_90')
        ->and($attention->firstWhere('key', 'ar_over_90')['count'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The charts
|--------------------------------------------------------------------------
*/

it('builds a cumulative revenue and cost curve by month', function () {
    // The S-curve PLAN.md §2 names: what the works have earned and cost, to
    // date, month by month — cumulative, because that is what makes it a curve
    // rather than a bar chart.
    $organization = Organization::factory()->create();
    projectWithLedger('1000000.0000', '600000.0000', $organization);

    $curve = kpis()->cumulativeCurve(months: 3);

    expect($curve['labels'])->toHaveCount(3)
        ->and($curve['labels'][2])->toBe('Jun 2026')
        ->and(end($curve['revenue']))->toBe('1000000.0000')
        ->and(end($curve['cost']))->toBe('600000.0000')
        // Nothing was posted before June, so the earlier months are flat at zero.
        ->and($curve['revenue'][0])->toBe('0.0000');
});

it('carries an earlier month forward, because a cumulative curve never falls', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $costCode = CostCode::factory()->for($organization)->create();

    app(LedgerPoster::class)->post(
        project: $project,
        costCode: $costCode,
        category: LedgerCategory::Revenue,
        amount: '250000.0000',
        sourceDocument: $project,
        documentNumber: 'REV-EARLY',
        cutoffType: CutoffType::Billing,
        documentDate: Carbon::parse('2026-04-10'),
        description: 'April revenue',
    );

    $curve = kpis()->cumulativeCurve(months: 3);

    // April, May, June: nothing in May or June, and the curve holds its level.
    expect($curve['revenue'])->toBe(['250000.0000', '250000.0000', '250000.0000']);
});

it('compares budget against actual cost per project', function () {
    [$project, $costCode] = budgetedProject('500000.0000');

    app(LedgerPoster::class)->post(
        project: $project,
        costCode: $costCode,
        category: LedgerCategory::Material,
        amount: '120000.0000',
        sourceDocument: $project,
        documentNumber: 'MAT-BVA',
        cutoffType: CutoffType::Opex,
        documentDate: Carbon::parse('2026-06-02'),
        description: 'Material',
    );

    $rows = kpis()->budgetVersusActual();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['budget'])->toBe('500000.0000')
        ->and($rows[0]['actual'])->toBe('120000.0000')
        ->and($rows[0]['project'])->toBe($project->code);
});

it('ranks the AR ageing buckets oldest first, so the worst is read first', function () {
    $summary = kpis()->receivableAgeing();

    expect(array_keys($summary))->toBe([
        AgingBucket::OverNinety->value,
        AgingBucket::SixtyOneToNinety->value,
        AgingBucket::ThirtyOneToSixty->value,
        AgingBucket::OneToThirty->value,
        AgingBucket::Current->value,
    ]);
});
