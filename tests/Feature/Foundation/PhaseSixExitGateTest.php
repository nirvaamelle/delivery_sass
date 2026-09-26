<?php

use App\Domain\Gates\GateFailedException;
use App\Domain\Opex\OpexCalendarService;
use App\Domain\Opex\OpexStage;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Requisitions\BudgetExceededException;
use App\Domain\Requisitions\RequisitionService;
use App\Models\CostCode;
use App\Models\OpexPeriod;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| The Phase 6 exit gate — P6-08, go-live readiness
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Part C, all four required:
|
|   1. The §7 demo walk-through runs start to finish ON STAGING, gates rejecting
|      as designed.
|   2. A backup has been restored onto a scratch database and the restore
|      verified.
|   3. B4's one-cycle audit is complete and its findings closed or accepted in
|      writing.
|   4. 2FA is enforced on every Finance, HR and Admin account.
|
| **Two of the four cannot be met by code, and this file does not pretend to.**
| Clause 1's "on staging" needs a server (Part D item 12). Clause 3 needs an audit
| somebody commissions. What is asserted here is everything the build CAN prove —
| clause 1's walk-through locally, clause 2, clause 4 — and the phase is recorded
| as not closed on the remainder.
|
| Clause 1 is walked on the SEEDED demo, not on fixtures: §7 is a demo, and a
| gate that built its own tidy project would prove the services work while the
| thing a reviewer actually opens is untested.
|
*/

beforeEach(function () {
    // The local disk is faked before seeding: DatabaseSeeder writes the admin's
    // TOTP secret to it in testing, and the real file belongs to the browser
    // console gate and the DEV database. Overwriting it from here would break
    // that gate with nothing in its output pointing at this suite.
    Storage::fake('local');

    artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
});

function demoProject(): Project
{
    return Project::withoutProjectScope(fn (): Project => Project::query()->where('code', 'MBI-2026-014')->sole());
}

/*
|--------------------------------------------------------------------------
| Clause 1 — the §7 walk-through, locally
|--------------------------------------------------------------------------
*/

it('puts all four chains into the ledger for one demo project', function () {
    // §7 steps 5, 6, 7 and 8, each ending "…appears in the ledger".
    $project = demoProject();

    foreach ([LedgerCategory::Material, LedgerCategory::Labor, LedgerCategory::Overhead, LedgerCategory::Revenue] as $category) {
        expect(bccomp(finalAccounts()->lifetimeTotal($project, $category), '0', 4))
            ->toBe(1, "No {$category->value} in the ledger for the demo project");
    }
});

it('rejects an over-budget requisition line on the demo project', function () {
    // §7 step 2: "the budget check blocks an over-budget line." 02.10.100 is
    // budgeted at 2,500,000 and the seeded cement PR already commits against it.
    //
    // Raised FIRST, outside the expectation, and only the SUBMIT is expected to
    // throw. The budget check lives in submit(), not raise() — the first draft of
    // this test wrapped raise() alone, which never checks the budget, so it would
    // have failed for the wrong reason and a "fix" loosening it would have hidden
    // a gate nobody exercised.
    $project = demoProject();
    $costCode = CostCode::query()->where('code', '02.10.100')->sole();
    $requisitions = app(RequisitionService::class);

    $requisition = $requisitions->raise($project, [[
        'cost_code_id' => $costCode->getKey(),
        'description' => 'Deliberately over budget',
        'amount' => '2500000.0000',
    ]]);

    expect(fn () => $requisitions->submit($requisition))->toThrow(BudgetExceededException::class);
});

it('refuses to close the demo OPEX month while its variance is unexplained', function () {
    // §7 step 7. The seeder leaves the month at budget review with a 29%
    // underspend on purpose; §7 says 12%, and both are over the 10% threshold.
    $period = OpexPeriod::query()->where('stage', OpexStage::BudgetReview)->sole();

    expect(fn () => app(OpexCalendarService::class)->advanceTo($period, OpexStage::Reporting, User::query()->firstOrFail()))
        ->toThrow(GateFailedException::class);
});

it('assembles the P and L and cost per unit from all four chains', function () {
    // §7 step 9.
    $project = demoProject();

    $pl = finalAccounts()->profitAndLoss($project);
    $unit = costPerUnit()->forProject($project);

    expect(bccomp($pl['revenue'], '0', 4))->toBe(1)
        ->and(bccomp($pl['total_cost'], '0', 4))->toBe(1)
        ->and($unit['measurable'])->toBeTrue();
});

it('refuses to seed the demo in production', function () {
    // §7's closing requirement: the reset "can never point at production".
    //
    // A thrown refusal rather than exit code 1: artisan() in a test reaches the
    // exception directly, because Laravel's console layer does not catch it
    // there. The real command line exits 1. This also proves the refusal comes
    // BEFORE any write — the demo is already seeded once by beforeEach, and the
    // demo seeder is not idempotent, so a guard that let seeding start would
    // surface here as a duplicate-key error instead of this refusal.
    app()['env'] = 'production';

    try {
        expect(fn () => artisan('db:seed', ['--force' => true])->run())
            ->toThrow(DomainException::class, 'production');
    } finally {
        app()['env'] = 'testing';
    }
});

/*
|--------------------------------------------------------------------------
| Clause 2 — a restore verified
|--------------------------------------------------------------------------
*/

it('has a restore rehearsal that verifies the ledger triggers came back', function () {
    // The full rehearsal is proven in BackupRestoreTest against a real dump. The
    // gate asserts it is wired and scheduled, so clause 2 stays true after go-live
    // rather than true once.
    expect(collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)->implode("\n"))
        ->toContain('backup:rehearse');
});

/*
|--------------------------------------------------------------------------
| Clause 4 — 2FA enforced
|--------------------------------------------------------------------------
*/

it('reports clause 4 met once every covered account has enrolled', function () {
    // Switched on here: 2FA ships switched OFF for now, and while it is off
    // clause 4 is not met at all (TwoFactorSwitchTest). This asserts what
    // enabling it will give.
    config()->set('security.two_factor_enabled', true);

    // The seed now creates a demo account for every role, and finance and HR
    // are covered roles that are deliberately NOT enrolled (see
    // DemoAccountsSeeder). So "every covered account enrolled" has to be made
    // true here first — the earlier version relied on the admin being the only
    // covered account, which stopped being so when those accounts were added.
    User::query()->get()
        ->filter(fn (User $user): bool => twoFactor()->isRequiredFor($user) && ! twoFactor()->isConfirmed($user))
        ->each(function (User $user): void {
            $secret = twoFactor()->enable($user);
            twoFactor()->confirm($user->fresh(), currentCodeFor($secret));
        });

    artisan('security:two-factor-status')->assertExitCode(0);

    userWithRole('hr-manager');

    artisan('security:two-factor-status')->assertExitCode(1);
});
