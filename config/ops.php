<?php

use App\Jobs\ComputePayrollRun;

/*
|--------------------------------------------------------------------------
| Operations
|--------------------------------------------------------------------------
*/

return [

    /*
     * Where the MySQL client binaries live, when they are not on PATH.
     *
     * This build has spent its whole life in a Laragon shell where they are
     * not, and a production host may put them somewhere unusual too.
     * Configuring the directory beats a hard-coded path that works on exactly
     * one machine — and beats assuming PATH, which is the assumption that made
     * `npm run test:console` fail once in P0-06 for reasons unrelated to the
     * test.
     */
    'mysql_bin_path' => env('MYSQL_BIN_PATH'),

    /*
     * P6-06. Who is alerted when a queued job fails, keyed by job class.
     *
     * A payroll run that fails in the queue is Finance's problem before it is
     * anybody else's: the symptom is a register that never appeared. Anything
     * not listed goes to `default_job_owner` rather than to nobody, because a
     * job added next year will not have been thought about here.
     */
    'job_owners' => [
        ComputePayrollRun::class => 'finance-manager',
    ],

    'default_job_owner' => 'admin',

    /*
     * P6-08. The password every seeded demo account is given outside local and
     * testing. Required there, and may not be `password`: the seeders create an
     * administrator and every matrix approver, and a default password on those
     * accounts on a public staging subdomain is a backdoor. See DemoSeedGuard.
     */
    'demo_admin_password' => env('DEMO_ADMIN_PASSWORD'),

];
