<?php

namespace Database\Seeders;

use App\Domain\Ops\DemoSeedGuard;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectRole;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * One demo sign-in per role.
 *
 * Per-screen access (P6-01a) gives each role a different system, and the demo
 * seed only created the accounts its sample documents happened to need. Nobody
 * could sign in as HR, finance or the managing director to see what those roles
 * get. And the project manager that did exist saw an empty system: P6-01 hides
 * every project a user is not assigned to, and nothing assigned them.
 *
 * Passwords come from DemoSeedGuard, like every other seeded account — the
 * default locally, and refused on staging unless DEMO_ADMIN_PASSWORD is set.
 *
 * Safe to run on an existing database: accounts are found before they are
 * created, an existing password is never overwritten, and an assignment that
 * already exists is left alone.
 *
 * `admin` is deliberately not here. DatabaseSeeder owns that account and its
 * two-factor enrolment, and two seeders writing one account is how they drift.
 *
 * **Finance and HR are covered by two-factor authentication and are deliberately
 * NOT enrolled here.** The admin can be enrolled by the seed only because its
 * secret is written to a file the browser console gate reads. An enrolment with a
 * secret nobody was shown would lock these accounts out the day
 * TWO_FACTOR_ENABLED is turned on — nobody could produce their code. Left
 * unenrolled, they are sent to the setup page on first sign-in instead, which is
 * what a real new finance or HR user should see. Until then
 * `security:two-factor-status` correctly lists them as outstanding.
 */
class DemoAccountsSeeder extends Seeder
{
    /** The project the demo documents are raised against. */
    private const DEMO_PROJECT = 'MBI-2026-014';

    /**
     * Role => display name. The email follows the pattern the demo chain already
     * uses for its approvers: `finance-manager` → finance.manager@construction.test.
     *
     * PLACEHOLDER: Part D item 5 — these are the build's roles, not the client's.
     */
    private const ACCOUNTS = [
        'finance-manager' => 'Finance Manager',
        'managing-director' => 'Managing Director',
        'procurement-head' => 'Procurement Head',
        'project-manager' => 'Project Manager',
        'hr-manager' => 'HR Manager',

        // Site staff — see config/access.php for what each opens.
        'site-engineer' => 'Site Engineer',
        'foreman' => 'Foreman',
        'timekeeper' => 'Timekeeper',
        'storekeeper' => 'Storekeeper',
    ];

    /**
     * Demo accounts that work ON the demo project, and as what.
     *
     * These roles are not unscoped by ProjectScope, so without an assignment they
     * sign in to a system with no projects on it. Finance, the managing director
     * and admin read across projects by role; procurement and HR screens are not
     * project-scoped; so only these need one.
     */
    private const PROJECT_ROLES = [
        'project-manager' => ProjectRole::ProjectManager,
        'site-engineer' => ProjectRole::SiteEngineer,
        'foreman' => ProjectRole::Foreman,
        'timekeeper' => ProjectRole::Timekeeper,
        'storekeeper' => ProjectRole::Storekeeper,
    ];

    public function run(): void
    {
        $guard = app(DemoSeedGuard::class);
        $environment = (string) app()->environment();

        $guard->assertMaySeed($environment);
        $password = $guard->adminPassword($environment, config('ops.demo_admin_password'));

        foreach (self::ACCOUNTS as $role => $name) {
            $user = User::firstOrCreate(
                ['email' => str_replace('-', '.', $role).'@construction.test'],
                ['name' => $name, 'password' => bcrypt($password)],
            );

            Role::findOrCreate($role);

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }

        $this->assignToDemoProject();
    }

    /**
     * Put the demo accounts that work on the demo project onto it.
     *
     * Found before it was fixed: the seeded project manager signed in to an empty
     * system, because P6-01 hides every project a user is not assigned to.
     */
    private function assignToDemoProject(): void
    {
        $project = Project::withoutProjectScope(
            fn (): ?Project => Project::query()->where('code', self::DEMO_PROJECT)->first(),
        );

        // Seeded before the demo chain, or on a database without it: nothing to
        // assign to, and that is not an error.
        if ($project === null) {
            return;
        }

        foreach (self::PROJECT_ROLES as $role => $projectRole) {
            $user = User::query()->where('email', str_replace('-', '.', $role).'@construction.test')->first();

            if ($user === null) {
                continue;
            }

            $assigned = ProjectAssignment::query()
                ->where('project_id', $project->getKey())
                ->where('user_id', $user->getKey())
                ->exists();

            if (! $assigned) {
                app(ProjectAccessService::class)->assign($user, $project, $projectRole);
            }
        }
    }
}
