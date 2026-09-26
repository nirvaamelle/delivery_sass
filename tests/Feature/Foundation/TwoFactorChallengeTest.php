<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
|--------------------------------------------------------------------------
| The per-login challenge — P6-05a, the rest of exit gate clause 4
|--------------------------------------------------------------------------
|
| P6-05 enforced ENROLMENT: a Finance, HR or Admin account cannot reach the
| panel until it holds a working authenticator. That is half of what "2FA is
| enforced" means, and the half that was recorded as a gap rather than glossed.
|
| This is the other half. A stolen password alone must not open the panel, which
| means the code is asked for **at sign-in**, not once at enrolment.
|
| **Once per session, not once per request.** A challenge on every page load is
| one people route around — and the thing being authenticated is the session,
| not the click.
|
| **The flag lives in the session, not on the user.** A column would make the
| second factor satisfied on every device at once the moment it was satisfied on
| one, which is the property a second factor exists to deny.
|
*/

// These suites prove 2FA itself, so they switch it on. It ships switched off
// for now (see TwoFactorSwitchTest); re-enabling must find it still working.
beforeEach(fn () => config()->set('security.two_factor_enabled', true));

it('challenges an enrolled account before the panel opens', function () {
    actingAs(enrolledFinanceUser()[0]);

    get('/admin')->assertRedirect(route('two-factor.challenge'));
});

it('opens the panel once the code is accepted', function () {
    [$user, $secret] = enrolledFinanceUser();
    actingAs($user);

    post(route('two-factor.challenge.verify'), ['code' => currentCodeFor($secret, 1)])
        ->assertRedirect('/admin');

    get('/admin')->assertSuccessful();
});

it('refuses a wrong code and keeps the panel shut', function () {
    [$user] = enrolledFinanceUser();
    actingAs($user);

    post(route('two-factor.challenge.verify'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    get('/admin')->assertRedirect(route('two-factor.challenge'));
});

it('asks only once per session, not once per request', function () {
    // A challenge on every page load is one people route around, and what is
    // being authenticated is the session rather than the click.
    [$user, $secret] = enrolledFinanceUser();
    actingAs($user);
    post(route('two-factor.challenge.verify'), ['code' => currentCodeFor($secret, 1)]);

    get('/admin')->assertSuccessful();
    get('/admin')->assertSuccessful();
});

it('does not challenge an account the gate does not cover', function () {
    actingAs(userWithRole('site-engineer'));

    get('/admin')->assertSuccessful();
});

it('sends an unenrolled covered account to setup rather than to the challenge', function () {
    // Order matters: challenging somebody with no authenticator asks them for a
    // code nothing can produce, which is a locked door with no key rather than
    // a second factor.
    actingAs(userWithRole('finance-manager'));

    get('/admin')->assertRedirect(route('two-factor.setup'));
});

it('does not carry the pass across a new session', function () {
    // The property a second factor exists to deny: satisfied on one device must
    // not mean satisfied on every device. The flag is session state, never a
    // column.
    [$user, $secret] = enrolledFinanceUser();
    actingAs($user);
    post(route('two-factor.challenge.verify'), ['code' => currentCodeFor($secret, 1)]);
    get('/admin')->assertSuccessful();

    session()->flush();

    get('/admin')->assertRedirect(route('two-factor.challenge'));
});

it('refuses a replayed code at the challenge too', function () {
    // The same guard as enrolment. Without it, a code read over a shoulder
    // opens a second session for the rest of its thirty-second window.
    [$user, $secret] = enrolledFinanceUser();
    $code = currentCodeFor($secret);
    actingAs($user);
    post(route('two-factor.challenge.verify'), ['code' => $code]);

    session()->flush();

    post(route('two-factor.challenge.verify'), ['code' => $code])
        ->assertSessionHasErrors('code');
});

it('leaves the challenge page reachable while unsatisfied', function () {
    // Or the redirect loops onto itself.
    [$user] = enrolledFinanceUser();
    actingAs($user);

    get(route('two-factor.challenge'))->assertSuccessful();
});

/**
 * A Finance account that has enrolled but has not yet been challenged.
 *
 * The suites present `currentCodeFor($secret, 1)` — the NEXT window's code —
 * because enrolment consumed this one and the replay guard is right to refuse
 * it. A real authenticator has rolled over by the time somebody signs in
 * again; the case where it has not is why `TwoFactorConfirmController` marks
 * the session passed at enrolment.
 *
 * @return array{0: User, 1: string}
 */
function enrolledFinanceUser(): array
{
    $user = userWithRole('finance-manager');
    $secret = twoFactor()->enable($user);
    twoFactor()->confirm($user->fresh(), currentCodeFor($secret));

    return [$user->fresh(), $secret];
}
