<?php

/*
|--------------------------------------------------------------------------
| Deploy pipeline — P0-16
|--------------------------------------------------------------------------
|
| PLAN.md §6 puts the pipeline on day one, not at the end, and the reason is
| that a deploy path first exercised in week fourteen is a deploy path nobody
| has debugged.
|
| These tests guard two things that go quietly wrong rather than loudly:
|
|   1. CI drifting away from the loop's own check list. If the pipeline runs
|      fewer checks than BUILD-LOOP.md Step 3, then green CI stops meaning what
|      everyone assumes it means — and nobody notices, because it is still
|      green.
|   2. A destructive migration reaching a server. `migrate:fresh` in a deploy
|      script drops every table. On staging that is an afternoon; on production
|      it is the company's books.
|
*/

function ci(): string
{
    return file_get_contents(base_path('.github/workflows/ci.yml'));
}

function deployScript(): string
{
    return file_get_contents(base_path('deploy.sh'));
}

function stagingEnv(): string
{
    return file_get_contents(base_path('.env.staging.example'));
}

it('runs every check the build loop requires', function (string $check) {
    // BUILD-LOOP.md Step 3, one test per line of it.
    expect(ci())->toContain($check);
})->with([
    'php artisan test',
    'pint --test',
    'phpstan analyse',
    'php artisan migrate',
    'npm run test:console',
]);

it('audits dependencies for known vulnerabilities', function () {
    // PLAN.md §3 security baseline names `composer audit` in CI explicitly.
    expect(ci())->toContain('composer audit');
});

it('runs CI against MySQL rather than SQLite', function () {
    // PLAN.md §2 picks MySQL for SELECT … FOR UPDATE on gapless numbering, and
    // the ledger's immutability triggers are MySQL too. A pipeline testing on
    // SQLite would pass while proving none of it.
    expect(ci())->toContain('mysql');
});

it('never runs a destructive migration on deploy', function () {
    // The one line that must never appear. migrate:fresh drops every table;
    // migrate:reset and wipe do the same by other names.
    // Separate statements rather than a chain: `->not` is only available on a
    // fresh expectation, not on the one an assertion hands back.
    $script = deployScript();

    expect($script)->not->toContain('migrate:fresh');
    expect($script)->not->toContain('migrate:reset');
    expect($script)->not->toContain('db:wipe');
});

it('migrates with force, because a deploy has no terminal to confirm at', function () {
    expect(deployScript())->toContain('migrate --force');
});

it('restarts queue workers after deploying', function () {
    // Long-running workers hold the old code in memory. Without this, a payroll
    // run after a deploy executes the previous release — and the symptom is
    // wrong numbers, not an error.
    expect(deployScript())->toContain('queue:restart');
});

it('installs production dependencies without dev packages', function () {
    expect(deployScript())->toContain('--no-dev');
});

it('keeps debug off in the staging template', function () {
    // PLAN.md §3: APP_DEBUG=false in production, always. Staging carries real
    // enough data to deserve the same rule, and a debug page leaks the
    // database credentials in its stack trace.
    expect(stagingEnv())->toContain('APP_DEBUG=false');
});

it('points the staging template at its own database', function () {
    // A staging deploy that inherits production's database is the mistake that
    // ends a company. §7's demo reset runs on staging.
    expect(stagingEnv())
        ->toContain('APP_ENV=staging')
        ->toContain('DB_DATABASE=construction_staging');
});

it('keeps Telescope out of production', function () {
    // PLAN.md §2: Telescope on staging only, never production.
    expect(stagingEnv())->toContain('TELESCOPE_ENABLED');
});
