<?php

use App\Domain\Budgets\BudgetStatus;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\LedgerPoster;
use App\Filament\Pages\ProjectProfitAndLoss;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Cost per unit of accomplishment — P6-08 gap, PLAN.md §7 step 9
|--------------------------------------------------------------------------
|
| §7 step 9: "Project P&L and cost per unit of accomplishment, assembled from all
| four chains." The P&L was built in P4-08. Cost per unit never was — the phrase
| sat in docblocks from P1-15 onward, quoted by the very service that should have
| produced it. Found while designing the Phase 6 exit gate, which walks §7.
|
| **The unit is one percentage point of VERIFIED accomplishment.** What a project
| has cost to date, divided by how far along both sides have signed that it is.
| A measurement the client has not countersigned is invisible here for the same
| reason it is invisible to the billing gate: an unagreed percentage would let
| the figure be improved by measuring optimistically.
|
| **Nothing verified means not measurable, never zero.** Zero cost per point reads
| as free work. Dividing by zero reads as a crash. Absence is not permission.
|
| **Benchmarked against the OPEN budget**, so the figure says whether it is good:
| a budget of 3,000,000 costs 30,000 per point, and a project spending 33,000 per
| point is ten percent over, visible at 40% complete rather than at 100%.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
    billingCalendarForMay();
});

afterEach(fn () => Carbon::setTestNow());

it('divides life-to-date cost by the verified percentage', function () {
    // 1,000,000 material + 200,000 labour at 40.00% verified = 30,000 per point.
    [$project, $costCode] = budgetedProject('3000000.0000');
    postCost($project, $costCode, LedgerCategory::Material, '1000000.0000');
    postCost($project, $costCode, LedgerCategory::Labor, '200000.0000');
    verifiedAccomplishment($project, '40.00');

    $unit = costPerUnit()->forProject($project);

    expect($unit['cost_to_date'])->toBe('1200000.0000')
        ->and($unit['verified_percent'])->toBe('40.00')
        ->and($unit['cost_per_percent'])->toBe('30000.0000');
});

it('leaves revenue out of the cost', function () {
    [$project, $costCode] = budgetedProject('3000000.0000');
    postCost($project, $costCode, LedgerCategory::Material, '1000000.0000');
    postCost($project, $costCode, LedgerCategory::Revenue, '9999999.0000');
    verifiedAccomplishment($project, '50.00');

    expect(costPerUnit()->forProject($project)['cost_to_date'])->toBe('1000000.0000');
});

it('reads the verified percentage, not a newer measurement nobody countersigned', function () {
    // Measuring optimistically must not improve the figure.
    [$project, $costCode] = budgetedProject('3000000.0000');
    postCost($project, $costCode, LedgerCategory::Material, '1200000.0000');
    verifiedAccomplishment($project, '40.00');
    accomplishments()->record($project, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), '80.00', User::factory()->create());

    expect(costPerUnit()->forProject($project)['verified_percent'])->toBe('40.00');
});

it('reports a project with nothing verified as not measurable, rather than zero or a crash', function () {
    [$project, $costCode] = budgetedProject('3000000.0000');
    postCost($project, $costCode, LedgerCategory::Material, '500000.0000');

    $unit = costPerUnit()->forProject($project);

    expect($unit['measurable'])->toBeFalse()
        ->and($unit['cost_per_percent'])->toBeNull();
});

it('benchmarks against the open budget', function () {
    // 3,000,000 over 100 points = 30,000 per point; spending exactly that is on plan.
    [$project, $costCode] = budgetedProject('3000000.0000');
    postCost($project, $costCode, LedgerCategory::Material, '1200000.0000');
    verifiedAccomplishment($project, '40.00');

    $unit = costPerUnit()->forProject($project);

    expect($unit['budgeted_cost_per_percent'])->toBe('30000.0000')
        ->and($unit['variance_percent'])->toBe('0.00');
});

it('reports overspend as a positive variance, visible long before completion', function () {
    // 33,000 per point against a plan of 30,000 is ten percent over, at 40%.
    [$project, $costCode] = budgetedProject('3000000.0000');
    postCost($project, $costCode, LedgerCategory::Material, '1320000.0000');
    verifiedAccomplishment($project, '40.00');

    expect(costPerUnit()->forProject($project)['variance_percent'])->toBe('10.00');
});

it('ignores a draft budget in the benchmark', function () {
    // A draft is somebody's working, not the number the project is held to.
    [$project, $costCode] = budgetedProject('3000000.0000');
    Budget::query()->create(['project_id' => $project->getKey(), 'name' => 'Revision in progress', 'status' => BudgetStatus::Draft])
        ->lines()->create(['cost_code_id' => $costCode->getKey(), 'amount' => '9000000.0000']);
    postCost($project, $costCode, LedgerCategory::Material, '1200000.0000');
    verifiedAccomplishment($project, '40.00');

    expect(costPerUnit()->forProject($project)['budgeted_cost_per_percent'])->toBe('30000.0000');
});

it('shows cost per unit on the project P and L page', function () {
    // §7 step 9 puts it beside the P&L; a figure only reachable from code is
    // not something a reviewer walking the demo will see.
    //
    // A project is created first. The page renders one section PER PROJECT, so
    // with none the label is never drawn — the first draft of this test would
    // have gone on failing after the page was fixed.
    budgetedProject('3000000.0000');
    actingAs(panelUser());

    get(ProjectProfitAndLoss::getUrl())
        ->assertSuccessful()
        ->assertSee('Cost per 1% accomplished');
});

// postCost() is used only here, so it stays here. costPerUnit() moved to
// tests/Support/OpexFixtures.php: the Phase 6 exit gate calls it too, and a helper
// declared in a test file exists only when that file happens to be loaded.

function postCost($project, $costCode, LedgerCategory $category, string $amount): void
{
    // The project stands in as the source document: LedgerPoster::post takes any
    // Model and records only its morph class and key. What is under test is the
    // division, not which document wrote the row.
    app(LedgerPoster::class)->post(
        project: $project,
        costCode: $costCode,
        category: $category,
        amount: $amount,
        sourceDocument: $project,
        documentNumber: 'CPU-'.uniqid(),
        cutoffType: CutoffType::Billing,
        documentDate: Carbon::parse('2026-05-15'),
        description: 'Cost per unit fixture',
    );
}
