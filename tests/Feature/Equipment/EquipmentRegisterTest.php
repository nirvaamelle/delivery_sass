<?php

use App\Domain\Equipment\DepreciationMethod;
use App\Domain\Equipment\EquipmentCostType;
use App\Domain\Equipment\EquipmentStatus;
use App\Domain\Equipment\NotDepreciableException;
use App\Domain\Equipment\Ownership;
use App\Models\CostCode;
use App\Models\Equipment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Equipment register — P1-13, closing F5
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md: "Equipment fuel, repair and depreciation is a named input to
| every monthly consolidation, and slide 9 requires equipment, tools and IT
| assets to be returned and logged at demobilization. PLAN.md §4 has no asset or
| equipment register at all."
|
| So a named OPEX input is currently unbuildable, and that is the finding. Three
| things have to become true here:
|
|   - a machine is in ONE place at a time, and the record says where;
|   - fuel and repairs land on the project that was actually using it;
|   - depreciation is a schedule, computed once, not a number recomputed monthly
|     by whoever opens the report.
|
| Built in Phase 1 rather than Phase 4 because equipment is ACQUIRED through
| procurement — retrofitting an asset register under a live PO chain is worse
| than building it alongside one.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('registers a company-owned machine', function () {
    $item = ownedExcavator(['code' => 'EQ-EXC-001']);

    expect($item->code)->toBe('EQ-EXC-001')
        ->and($item->status)->toBe(EquipmentStatus::Available);
});

it('refuses two machines with the same code', function () {
    // The code is what a foreman writes on a fuel slip. Two machines answering
    // to it makes every cost on both of them unattributable.
    ownedExcavator(['code' => 'EQ-DUP']);

    expect(fn () => ownedExcavator(['code' => 'EQ-DUP']))
        ->toThrow(QueryException::class);
});

it('assigns a machine to a project', function () {
    $item = ownedExcavator();
    $project = Project::factory()->create();

    $assignment = equipment()->assign($item, $project, Carbon::parse('2026-05-01'), User::factory()->create());

    expect($assignment->released_on)->toBeNull()
        ->and($item->fresh()->status)->toBe(EquipmentStatus::Deployed);
});

it('refuses to assign a machine that is already on another project', function () {
    // THE ONE THAT MATTERS for cost attribution. A machine on two projects at
    // once means every hour of fuel it burns can be charged to either, and the
    // cost per unit on both is whatever somebody decided that morning.
    $item = ownedExcavator();

    equipment()->assign($item, Project::factory()->create(), Carbon::parse('2026-05-01'));

    expect(fn () => equipment()->assign($item, Project::factory()->create(), Carbon::parse('2026-05-10')))
        ->toThrow(DomainException::class);
});

it('assigns a machine again once it has been released', function () {
    // Demobilization — slide 9's "returned and logged". Releasing is what makes
    // the machine available, and the previous assignment stays on the record
    // rather than being overwritten.
    $item = ownedExcavator();
    $first = equipment()->assign($item, Project::factory()->create(), Carbon::parse('2026-05-01'));

    equipment()->release($first, Carbon::parse('2026-05-20'), 'Demobilized, returned to yard.');

    $second = equipment()->assign($item, Project::factory()->create(), Carbon::parse('2026-05-21'));

    expect($item->fresh()->assignments()->count())->toBe(2)
        ->and($second->released_on)->toBeNull();
});

it('refuses a release dated before the assignment began', function () {
    $item = ownedExcavator();
    $assignment = equipment()->assign($item, Project::factory()->create(), Carbon::parse('2026-05-10'));

    expect(fn () => equipment()->release($assignment, Carbon::parse('2026-05-01')))
        ->toThrow(DomainException::class);
});

it('charges fuel to the project the machine was on', function () {
    $item = ownedExcavator();
    $project = Project::factory()->create();
    equipment()->assign($item, $project, Carbon::parse('2026-05-01'));

    $cost = equipment()->recordCost(
        $item,
        EquipmentCostType::Fuel,
        '18500.0000',
        Carbon::parse('2026-05-12'),
        CostCode::factory()->create(['organization_id' => $project->organization_id]),
    );

    expect($cost->project_id)->toBe($project->getKey());
    expectMoney($cost->fresh()->amount);
});

it('refuses a cost on a machine assigned to nobody', function () {
    // An unassigned machine has no project to carry the cost. Guessing one is
    // how a yard's fuel bill ends up on whichever project was open in the form.
    $item = ownedExcavator();

    expect(fn () => equipment()->recordCost(
        $item,
        EquipmentCostType::Fuel,
        '18500.0000',
        Carbon::parse('2026-05-12'),
        CostCode::factory()->create(),
    ))->toThrow(DomainException::class);
});

it('refuses a cost dated outside the assignment that would carry it', function () {
    // The machine was on site from the 10th. Fuel dated the 2nd belongs to
    // wherever it actually was, and that is a different project's cost.
    $item = ownedExcavator();
    $project = Project::factory()->create();
    equipment()->assign($item, $project, Carbon::parse('2026-05-10'));

    expect(fn () => equipment()->recordCost(
        $item,
        EquipmentCostType::Fuel,
        '18500.0000',
        Carbon::parse('2026-05-02'),
        CostCode::factory()->create(['organization_id' => $project->organization_id]),
    ))->toThrow(DomainException::class);
});

it('builds a straight-line depreciation schedule over the useful life', function () {
    // 1,200,000 less 200,000 salvage is 1,000,000 depreciable over 60 months.
    $item = ownedExcavator();

    $schedule = depreciation()->generate($item);

    expect($schedule)->toHaveCount(60);
    expectMoney((string) $schedule->first()->amount);
    expect((string) $schedule->first()->amount)->toBe('16666.6667');
});

it('depreciates exactly the depreciable base, to the centavo', function () {
    // THE ONE THAT MATTERS for the ledger. 1,000,000 / 60 does not divide, and
    // sixty rounded instalments come to 1,000,000.0020 — two-tenths of a centavo
    // of asset that never existed, posted to a project as real cost. On a fleet
    // and over years that is a reconciliation nobody can source. The final
    // period carries the difference instead.
    $item = ownedExcavator();

    depreciation()->generate($item);

    expect(depreciation()->totalScheduled($item->fresh()))->toBe('1000000.0000');
});

it('refuses to depreciate a rented machine', function () {
    // A rental is an expense as it is incurred. Depreciating it would capitalise
    // a cost the company already paid in full and count it twice.
    $item = ownedExcavator(['ownership' => Ownership::Rented, 'acquisition_cost' => '0.0000']);

    expect(fn () => depreciation()->generate($item))
        ->toThrow(NotDepreciableException::class);
});

it('refuses a second schedule for one machine', function () {
    // Two schedules mean two depreciation runs a month, both looking correct.
    $item = ownedExcavator();
    depreciation()->generate($item);

    expect(fn () => depreciation()->generate($item->fresh()))
        ->toThrow(DomainException::class);
});

it('never schedules depreciation below salvage value', function () {
    // The last period is the one that would overshoot if the remainder were
    // simply added to a full instalment.
    $item = ownedExcavator();
    $schedule = depreciation()->generate($item);

    $last = $schedule->last();
    $base = bcsub('1200000.0000', '200000.0000', 4);

    // 16,666.6667 sixty times is 1,000,000.002. The final instalment carries the
    // difference rather than the asset gaining two-tenths of a centavo.
    expect(bccomp(depreciation()->totalScheduled($item->fresh()), $base, 4))->toBe(0)
        ->and((string) $last->amount)->toBe('16666.6647')
        ->and($last->period_year)->toBe(2030)
        ->and($last->period_month)->toBe(12);
});

it('uses straight line as the recorded method, not an assumed one', function () {
    // PLACEHOLDER: Part D item 15 — the client has not confirmed the method or
    // the life. It is a column per machine rather than a constant, so answering
    // it later is a data change and not a code change.
    $item = ownedExcavator();

    expect($item->depreciation_method)->toBe(DepreciationMethod::StraightLine);
});
