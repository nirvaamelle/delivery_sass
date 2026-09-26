<?php

use App\Domain\Projects\ProjectRole;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Models\Project;
use App\Models\Punchlist;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Per-project row-level scope — P6-01
|--------------------------------------------------------------------------
|
| PLAN.md §3, deferred to this phase since P0-14: "opening the panel is not
| access to the data — that is per-resource authorisation and the per-project
| row-level policies."
|
| **The scope is in the QUERY, not in the view.** A screen that filters what it
| renders leaves every hidden record addressable by id: the row is still in the
| result set, still countable in a badge, still returned by a relation. This is
| a global Eloquent scope on the models that carry a project, so a foreman on
| the Cebu job cannot reach the Davao job's costs through a URL, a relation, or
| an export.
|
| **Absence of an assignment is not absence of a rule.** A user assigned to no
| project sees no projects — not all of them. That is the direction this kind of
| bug always fails in: an empty assignment list read as "no filter" hands a new
| starter the whole company on their first login.
|
| **Somebody has to be able to see everything.** Finance closes the books across
| projects and the managing director signs at tier 4, so the scope is lifted for
| named roles rather than bypassed per screen — a bypass written into each
| resource is one somebody forgets on the resource that matters.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-20 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('shows a user only the projects they are assigned to', function () {
    $mine = Project::factory()->create(['code' => 'PRJ-MINE']);
    Project::factory()->create(['code' => 'PRJ-THEIRS']);

    $user = User::factory()->create();
    projects()->assign($user, $mine, ProjectRole::SiteEngineer);

    actingAs($user);

    expect(Project::query()->pluck('code')->all())->toBe(['PRJ-MINE']);
});

it('shows a user with no assignment no projects at all', function () {
    // The direction this fails in matters. An empty assignment list read as "no
    // filter" hands a new starter every project in the company.
    Project::factory()->create();
    Project::factory()->create();

    actingAs(User::factory()->create());

    expect(Project::query()->count())->toBe(0);
});

it('hides a record on another project even when its id is known', function () {
    // The whole reason this is a query scope and not a view filter.
    $theirs = Project::factory()->create();
    $punchlist = punchlistOn($theirs);

    actingAs(assignedTo(Project::factory()->create()));

    expect(Punchlist::query()->find($punchlist->getKey()))->toBeNull();
});

it('scopes the documents that carry a project, not only the project itself', function () {
    $theirs = Project::factory()->create();
    punchlistOn($theirs);
    $mine = Project::factory()->create();
    $ours = punchlistOn($mine);

    actingAs(assignedTo($mine));

    expect(Punchlist::query()->pluck('id')->all())->toBe([$ours->getKey()]);
});

it('lifts the scope for a role that has to see every project', function () {
    // Finance closes the books across projects; a scope they cannot see past
    // would make the consolidation wrong rather than restricted.
    Project::factory()->create(['code' => 'PRJ-A']);
    Project::factory()->create(['code' => 'PRJ-B']);

    actingAs(userWithRole('finance-manager'));

    expect(Project::query()->count())->toBe(2);
});

it('does not lift the scope for an ordinary role', function () {
    Project::factory()->create();

    actingAs(userWithRole('site-engineer'));

    expect(Project::query()->count())->toBe(0);
});

it('leaves the scope off entirely when nobody is signed in', function () {
    // A queued job, a console command and a migration all run unauthenticated.
    // Scoping them to nobody's projects would silently stop the payroll run.
    Project::factory()->create();
    Project::factory()->create();

    expect(Project::query()->count())->toBe(2);
});

it('lets a service reach across projects when it must, deliberately', function () {
    // The escape hatch is named and explicit, so a reader can find every place
    // the scope is stepped around.
    $theirs = Project::factory()->create();
    punchlistOn($theirs);

    actingAs(assignedTo(Project::factory()->create()));

    expect(Punchlist::query()->count())->toBe(0)
        ->and(Punchlist::withoutProjectScope(fn (): int => Punchlist::query()->count()))->toBe(1);
});

it('refuses to assign the same person to one project twice', function () {
    // Two assignments is two answers to what somebody's role on the job is, and
    // the scope would count the project twice in every subquery.
    $project = Project::factory()->create();
    $user = User::factory()->create();
    projects()->assign($user, $project, ProjectRole::SiteEngineer);

    expect(fn () => projects()->assign($user, $project, ProjectRole::Foreman))
        ->toThrow(DomainException::class, 'already assigned');
});

it('removes an assignment and the access with it', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    projects()->assign($user, $project, ProjectRole::SiteEngineer);

    projects()->unassign($user, $project);
    actingAs($user->fresh());

    expect(Project::query()->count())->toBe(0);
});

it('renders a scoped screen without leaking another project record', function () {
    // The screens inherit the scope because it lives on the model. There is no
    // per-resource filter to forget.
    $theirs = Project::factory()->create();
    $hidden = punchlistOn($theirs);
    $mine = Project::factory()->create();
    $shown = punchlistOn($mine);

    // A project manager: allowed the close-out screens by role, and still
    // limited to their own projects by assignment — the two rules together.
    $user = assignedTo($mine);
    $user->assignRole(Role::findOrCreate('project-manager'));

    actingAs($user->fresh());

    get(PunchlistsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($shown->number)
        ->assertDontSee($hidden->number);
});
