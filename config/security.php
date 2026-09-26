<?php

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
|
| Which roles must hold a second factor — Phase 6's exit gate clause 4, in the
| gate's own words: "2FA is enforced on every Finance, HR and Admin account."
|
| Configured rather than hard-coded, and deliberately NOT widened beyond the
| three the clause names. Enforcing on a site role would lock a timekeeper out
| of the system over a rule nobody agreed to, and this build does not invent
| policy the client has not been asked for. If they want it everywhere, that is
| a line in this file.
|
*/

return [

    /*
     * Whether two-factor authentication is ENFORCED at all.
     *
     * Switched OFF for now at the client's request, to be enabled later. Nothing
     * is removed: enrolment, the per-login challenge and the replay guard stay
     * built and tested. While this is false, nobody is sent to the authenticator
     * setup page or asked for a code — and Phase 6 exit gate clause 4 does NOT
     * hold, which `security:two-factor-status` reports instead of showing green.
     *
     * To enable: TWO_FACTOR_ENABLED=true in .env.
     */
    'two_factor_enabled' => (bool) env('TWO_FACTOR_ENABLED', false),

    'two_factor_roles' => [
        'finance-manager',
        'hr-manager',
        'admin',
    ],

];
