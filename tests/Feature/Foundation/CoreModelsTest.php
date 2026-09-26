<?php

use App\Domain\Budgets\BudgetStatus;
use App\Domain\Projects\ProjectPhase;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostCode;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Core models — P0-05
|--------------------------------------------------------------------------
|
| The project spine from PLAN.md §4: organizations, projects, cost codes,
| budgets and budget lines. Everything the four chains post against hangs off
| these five tables, so the contracts they establish here are the ones the
| ledger inherits.
|
| F1 is closed here: `projects.phase` carries all four phases from the start.
| No chain is scoped to a phase subset, so no table may assume one.
|
*/

it('places a project under an organization', function () {
    $organization = Organization::factory()->create(['name' => 'MBI Construction']);

    $project = Project::factory()->for($organization)->create();

    expect($project->organization->name)->toBe('MBI Construction')
        ->and($organization->projects()->pluck('id')->all())->toBe([$project->id]);
});

it('opens a project in every one of the four phases', function () {
    // F1. All four chains run phases 1→4, so every phase must be reachable from
    // day one — a phase enum missing a value would silently scope a chain out.
    expect(ProjectPhase::cases())->toHaveCount(4);

    foreach (ProjectPhase::cases() as $phase) {
        $project = Project::factory()->create(['phase' => $phase]);

        expect($project->fresh()->phase)->toBe($phase);
    }
});

it('rejects a project phase the database does not know', function () {
    // Enforced by the column, not by Eloquent. PLAN.md §5: a control that only
    // exists in PHP is bypassed by a queued job or an import.
    $organization = Organization::factory()->create();

    expect(fn () => DB::table('projects')->insert([
        'organization_id' => $organization->id,
        'code' => 'MBI-2026-999',
        'name' => 'Invalid phase project',
        'client_name' => 'Any Client',
        'phase' => 'demolition',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a second project carrying a code already in use', function () {
    // The project code is half of the handoff spine — every document in every
    // chain carries it. Two projects sharing one code would merge two P&Ls.
    Project::factory()->create(['code' => 'MBI-2026-001']);

    expect(fn () => Project::factory()->create(['code' => 'MBI-2026-001']))
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Cost codes — the WBS tree
|--------------------------------------------------------------------------
|
| The other half of the handoff spine. PLAN.md §1: every document carries the
| project code AND the cost code. A cost code that cannot be trusted to be
| unique, or a tree that can contain a cycle, breaks every rollup built on it.
|
*/

it('nests cost codes into a WBS tree', function () {
    $organization = Organization::factory()->create();

    $division = CostCode::factory()->for($organization)->create(['code' => '02', 'name' => 'Sitework']);
    $section = CostCode::factory()->for($organization)->create(['code' => '02.10', 'parent_id' => $division->id]);
    $item = CostCode::factory()->for($organization)->create(['code' => '02.10.100', 'parent_id' => $section->id]);

    expect($item->parent->code)->toBe('02.10')
        ->and($section->parent->code)->toBe('02')
        ->and($division->parent)->toBeNull()
        ->and($division->children()->pluck('code')->all())->toBe(['02.10'])
        ->and($item->fresh()->wbsPath())->toBe('02 › 02.10 › 02.10.100');
});

it('rejects a cost code that would be its own ancestor', function () {
    // A cycle in the WBS tree makes every budget rollup non-terminating.
    $organization = Organization::factory()->create();
    $parent = CostCode::factory()->for($organization)->create(['code' => '03']);
    $child = CostCode::factory()->for($organization)->create(['code' => '03.10', 'parent_id' => $parent->id]);

    expect(fn () => $parent->update(['parent_id' => $child->id]))
        ->toThrow(DomainException::class);
});

it('rejects a duplicate cost code inside one organization', function () {
    $organization = Organization::factory()->create();
    CostCode::factory()->for($organization)->create(['code' => '02.10']);

    expect(fn () => CostCode::factory()->for($organization)->create(['code' => '02.10']))
        ->toThrow(QueryException::class);
});

it('allows the same cost code in a different organization', function () {
    // Proves the uniqueness is scoped, not global — Part D item 10 may make
    // organizations a real tenant boundary and each tenant owns its own WBS.
    CostCode::factory()->for(Organization::factory())->create(['code' => '02.10']);
    $second = CostCode::factory()->for(Organization::factory())->create(['code' => '02.10']);

    expect($second->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Budgets and budget lines
|--------------------------------------------------------------------------
|
| PLAN.md §5, the first hard control: "No PR without a cost code and confirmed
| budget availability." The gate itself is P0-11 — what P0-05 owes it is a
| budget line that cannot exist without a cost code and cannot be duplicated.
|
*/

it('opens a budget against a project', function () {
    $project = Project::factory()->create();

    $budget = Budget::factory()->for($project)->create(['status' => BudgetStatus::Open]);

    expect($budget->project->id)->toBe($project->id)
        ->and($budget->status)->toBe(BudgetStatus::Open)
        ->and($project->budgets()->count())->toBe(1);
});

it('holds budget line amounts as exact decimal money', function () {
    // PLAN.md §4: money is DECIMAL(18,4) everywhere — never float, never
    // integer cents. A value at the top of the range must survive the round
    // trip digit for digit; a float column would quietly round it.
    $line = BudgetLine::factory()->create(['amount' => '12345678901234.5678']);

    $amount = $line->fresh()->amount;

    expectMoney($amount);
    expect($amount)->toBe('12345678901234.5678');
});

it('rejects a budget line with no cost code', function () {
    // The control the PR budget check rests on. Enforced by a non-nullable FK,
    // so an import cannot write an uncoded line either.
    $budget = Budget::factory()->create();

    expect(fn () => DB::table('budget_lines')->insert([
        'budget_id' => $budget->id,
        'cost_code_id' => null,
        'amount' => '1000.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a budget line pointing at a cost code that does not exist', function () {
    $budget = Budget::factory()->create();

    expect(fn () => DB::table('budget_lines')->insert([
        'budget_id' => $budget->id,
        'cost_code_id' => 999999,
        'amount' => '1000.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a second budget line for the same cost code in one budget', function () {
    // Two lines for one cost code makes "budget availability" ambiguous, and
    // the PR gate has to resolve to a single number.
    $budget = Budget::factory()->create();
    $costCode = CostCode::factory()->create();

    BudgetLine::factory()->for($budget)->for($costCode)->create();

    expect(fn () => BudgetLine::factory()->for($budget)->for($costCode)->create())
        ->toThrow(QueryException::class);
});
