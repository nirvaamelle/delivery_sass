<?php

use App\Http\Controllers\TwoFactorChallengeController;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The 2FA switch
|--------------------------------------------------------------------------
|
| Two-factor authentication (P6-05, P6-05a) is switched OFF for now at the
| client's request, to be enabled later. It is a switch rather than a removal:
| enrolment, the per-login challenge and the replay guard all stay built and
| tested, and turning enforcement back on is `TWO_FACTOR_ENABLED=true`.
|
| While it is off, Phase 6 exit gate clause 4 ("2FA is enforced on every Finance,
| HR and Admin account") does NOT hold, and the status command says so rather
| than reporting green.
|
*/

it('lets an unenrolled finance account into the panel while 2FA is switched off', function () {
    config()->set('security.two_factor_enabled', false);

    actingAs(userWithRole('finance-manager'));

    get('/admin')->assertSuccessful();
});

it('does not challenge an enrolled account while 2FA is switched off', function () {
    config()->set('security.two_factor_enabled', false);

    $user = userWithRole('admin');
    $secret = twoFactor()->enable($user);
    twoFactor()->confirm($user->fresh(), currentCodeFor($secret));

    actingAs($user->fresh());

    get('/admin')->assertSuccessful();
});

it('enforces again the moment it is switched back on', function () {
    // The point of a switch rather than a deletion: re-enabling is a setting.
    config()->set('security.two_factor_enabled', true);

    actingAs(userWithRole('finance-manager'));

    get('/admin')->assertRedirect(route('two-factor.setup'));
});

it('still asks for the code at sign-in once switched back on', function () {
    config()->set('security.two_factor_enabled', true);

    $user = userWithRole('admin');
    $secret = twoFactor()->enable($user);
    twoFactor()->confirm($user->fresh(), currentCodeFor($secret));
    session()->forget(TwoFactorChallengeController::SESSION_KEY);

    actingAs($user->fresh());

    get('/admin')->assertRedirect(route('two-factor.challenge'));
});

it('reports clause 4 as not met while switched off, rather than green', function () {
    // An empty "outstanding" list would otherwise read as 2FA being enforced.
    config()->set('security.two_factor_enabled', false);

    artisan('security:two-factor-status')
        ->expectsOutputToContain('switched off')
        ->assertExitCode(1);
});
