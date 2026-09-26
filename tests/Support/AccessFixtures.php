<?php

/*
|--------------------------------------------------------------------------
| Access and project-scope fixtures — P6-01
|--------------------------------------------------------------------------
|
| Every builder here goes through `ProjectAccessService`, so a fixture cannot
| create an assignment the application itself would refuse.
|
*/

use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectRole;
use App\Domain\Security\TwoFactorService;
use App\Http\Controllers\TwoFactorChallengeController;
use App\Models\Project;
use App\Models\Punchlist;
use App\Models\User;
use Illuminate\Support\Carbon;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

function projects(): ProjectAccessService
{
    return app(ProjectAccessService::class);
}

/**
 * A user who can see exactly one project.
 */
function assignedTo(Project $project, ProjectRole $role = ProjectRole::SiteEngineer): User
{
    $user = User::factory()->create();
    projects()->assign($user, $project, $role);

    return $user->fresh();
}

/**
 * A user holding a named role and no project assignment.
 *
 * The pairing is deliberate: it is what tells an unscoped role apart from a
 * scoped one, since a user with both would pass either way.
 */
function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate($role));

    return $user->fresh();
}

function twoFactor(): TwoFactorService
{
    return app(TwoFactorService::class);
}

/**
 * Somebody who may read across every project.
 *
 * The screens suites use this rather than a bare factory user. Under P6-01 a
 * user with no assignment sees nothing, which is the policy working — so a test
 * asserting a screen shows a record has to say who is looking at it, and an
 * administrator is who actually opens these screens.
 */
function panelUser(): User
{
    $user = userWithRole('admin');

    // Enrolled, because P6-05 enforces a second factor on exactly this role and
    // an unenrolled admin is redirected to the setup page before any screen
    // renders. A fixture that skipped it would be asserting on a panel the
    // gate does not actually let this account into.
    $secret = twoFactor()->enable($user);
    twoFactor()->confirm($user->fresh(), currentCodeFor($secret));

    // And already past P6-05a's challenge. The fixture stands for an
    // administrator who has signed in and answered it — which is who is looking
    // at the screen a screens test is asserting on.
    session([TwoFactorChallengeController::SESSION_KEY => now()->toDateTimeString()]);

    return $user->fresh();
}

/**
 * The code a real authenticator app would be showing.
 *
 * `oathTotp()` rather than `getCurrentOtp()`: the latter takes no window
 * argument, so asking it for a later code silently returns the current one.
 */
function currentCodeFor(string $secret, int $windowsAhead = 0): string
{
    $google = new Google2FA;

    return $google->oathTotp($secret, ((int) $google->getTimestamp()) + $windowsAhead);
}

/**
 * Sign in as somebody who can see exactly one project.
 */
function actingAsAssignee(Project $project): User
{
    $user = assignedTo($project);
    auth()->login($user);

    return $user;
}

/**
 * A punchlist on a named project, built through the real services.
 *
 * The scope suites need documents on two different projects, and a punchlist is
 * the cheapest document in the build that carries `project_id` directly.
 */
function punchlistOn(Project $project): Punchlist
{
    return Punchlist::withoutProjectScope(function () use ($project): Punchlist {
        $project->update(['phase' => ProjectPhase::Construction]);

        $certificate = substantialCompletion()->certify(
            $project->fresh(),
            Carbon::parse('2026-05-18'),
            User::factory()->create(),
            clientRepresentative: 'A. Reyes, Project Director',
        );

        return punchlists()->issue($certificate, User::factory()->create(), Carbon::parse('2026-05-20'));
    });
}
