<?php

use App\Domain\Security\TwoFactorService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Two-factor authentication — P6-05, exit gate clause 4
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md's go-live gate, clause 4: "**2FA is enforced on every Finance,
| HR and Admin account.**"
|
| **Enforced, not offered.** An option nobody has to take is taken by nobody, and
| the accounts this clause names are the ones that can move money, see salaries
| and grant access. So a user holding one of those roles is stopped at the panel
| door until they have set it up — not warned, not nagged.
|
| **The secret is encrypted at rest**, under the rule PLAN.md §3 already applies
| to bank details and government numbers. A TOTP secret in plaintext is a second
| factor anybody with a database dump holds too, which is to say not a second
| factor.
|
| **Setting it up is not the same as confirming it.** A secret generated but
| never verified against a code means somebody scanned a QR and closed the tab;
| enforcing on the secret alone would lock them out of the system with a factor
| they never proved they can produce.
|
| **A used code cannot be replayed.** TOTP windows are thirty seconds wide, and
| a code read over somebody's shoulder is good for the rest of that window unless
| the last one accepted is remembered.
|
*/

// These suites prove 2FA itself, so they switch it on. It ships switched off
// for now (see TwoFactorSwitchTest); re-enabling must find it still working.
beforeEach(fn () => config()->set('security.two_factor_enabled', true));

it('requires two factor for the roles the gate names', function (string $role) {
    expect(twoFactor()->isRequiredFor(userWithRole($role)))->toBeTrue();
})->with(['finance-manager', 'hr-manager', 'admin']);

it('does not require it of a site role', function () {
    // Not a security judgement about foremen — a judgement about what the gate
    // asks for. Widening it beyond the clause would be inventing a rule the
    // client has not agreed to.
    expect(twoFactor()->isRequiredFor(userWithRole('site-engineer')))->toBeFalse();
});

it('generates an encrypted secret that is never stored in plaintext', function () {
    // Read straight off the raw column, the same way P3-01 proves the 201 file
    // holds no plaintext government numbers.
    $user = userWithRole('finance-manager');

    $secret = twoFactor()->enable($user);
    $raw = (string) DB::table('users')->where('id', $user->getKey())->value('two_factor_secret');

    expect($raw)->not->toBe($secret)
        ->and($raw)->not->toContain($secret)
        ->and($raw)->not->toBe('');
});

it('is not confirmed until a real code has been produced', function () {
    // Scanning a QR and closing the tab is not enrolment. Enforcing on the
    // secret alone would lock somebody out with a factor they never proved.
    $user = userWithRole('finance-manager');
    twoFactor()->enable($user);

    expect(twoFactor()->isConfirmed($user->fresh()))->toBeFalse();
});

it('confirms when the code from the secret is presented', function () {
    $user = userWithRole('finance-manager');
    $secret = twoFactor()->enable($user);

    twoFactor()->confirm($user->fresh(), currentCodeFor($secret));

    expect(twoFactor()->isConfirmed($user->fresh()))->toBeTrue();
});

it('refuses a wrong code', function () {
    $user = userWithRole('finance-manager');
    twoFactor()->enable($user);

    expect(fn () => twoFactor()->confirm($user->fresh(), '000000'))
        ->toThrow(DomainException::class, 'not valid');
});

it('refuses to confirm before anything was enabled', function () {
    expect(fn () => twoFactor()->confirm(userWithRole('finance-manager'), '123456'))
        ->toThrow(DomainException::class, 'has not been set up');
});

it('refuses to replay the code that was just used', function () {
    // A TOTP window is thirty seconds wide. A code read over somebody's
    // shoulder is good for the rest of it unless the last one accepted is
    // remembered.
    $user = userWithRole('finance-manager');
    $secret = twoFactor()->enable($user);
    $code = currentCodeFor($secret);
    twoFactor()->confirm($user->fresh(), $code);

    expect(fn () => twoFactor()->verify($user->fresh(), $code))
        ->toThrow(DomainException::class, 'already been used');
});

it('accepts a later code from the same secret', function () {
    $user = userWithRole('finance-manager');
    $secret = twoFactor()->enable($user);
    twoFactor()->confirm($user->fresh(), currentCodeFor($secret));

    // A different window, which is what the replay guard must not block.
    expect(twoFactor()->verify($user->fresh(), currentCodeFor($secret, 1)))->toBeTrue();
});

it('turns it off only for somebody the gate does not name', function () {
    // Disabling 2FA on a finance account is how clause 4 stops being true
    // quietly, three months after go-live.
    $user = userWithRole('finance-manager');
    $secret = twoFactor()->enable($user);
    twoFactor()->confirm($user->fresh(), currentCodeFor($secret));

    expect(fn () => twoFactor()->disable($user->fresh()))
        ->toThrow(DomainException::class, 'required for');
});

it('lets an ordinary user turn it off again', function () {
    $user = userWithRole('site-engineer');
    $secret = twoFactor()->enable($user);
    twoFactor()->confirm($user->fresh(), currentCodeFor($secret));

    twoFactor()->disable($user->fresh());

    expect(twoFactor()->isConfirmed($user->fresh()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Enforcement — the word the gate uses
|--------------------------------------------------------------------------
*/

it('stops a finance account at the panel door until it is enrolled', function () {
    actingAs(userWithRole('finance-manager'));

    get('/admin')->assertRedirect(route('two-factor.setup'));
});

it('stops sending the same account to setup once it is enrolled', function () {
    // Enrolment gets past the SETUP redirect. It does not get into the panel —
    // P6-05a adds the per-login challenge, and this account has not answered
    // one yet. Asserting the destination rather than merely "not setup" is what
    // keeps this test honest about which control it is proving.
    $user = userWithRole('finance-manager');
    $secret = twoFactor()->enable($user);
    twoFactor()->confirm($user->fresh(), currentCodeFor($secret));

    actingAs($user->fresh());

    get('/admin')->assertRedirect(route('two-factor.challenge'));
});

it('does not stop a site account that has no 2FA', function () {
    // The gate names three roles. Enforcing beyond them would lock a timekeeper
    // out of the system over a rule nobody agreed.
    actingAs(userWithRole('site-engineer'));

    get('/admin')->assertSuccessful();
});

it('reports every account the gate covers that is not yet enrolled', function () {
    // Clause 4 is evidence somebody produces at go-live. "We turned it on" is
    // not evidence; the list of accounts still outstanding is, and an empty
    // list is the clause being met.
    userWithRole('finance-manager');
    $ready = userWithRole('hr-manager');
    $secret = twoFactor()->enable($ready);
    twoFactor()->confirm($ready->fresh(), currentCodeFor($secret));

    $outstanding = twoFactor()->outstanding();

    expect($outstanding->pluck('email')->all())->not->toContain($ready->email)
        ->and($outstanding->count())->toBe(1);
});

it('can be checked from the console', function () {
    userWithRole('finance-manager');

    \Pest\Laravel\artisan('security:two-factor-status')->assertExitCode(1);
});

it('passes the console check when nobody is outstanding', function () {
    \Pest\Laravel\artisan('security:two-factor-status')->assertExitCode(0);
});

it('binds the service as a singleton so the replay guard is one guard', function () {
    expect(app(TwoFactorService::class))->toBe(app(TwoFactorService::class));
});
