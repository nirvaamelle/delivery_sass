<?php

namespace App\Http\Controllers;

use App\Domain\Security\TwoFactorService;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The per-login challenge — P6-05a, the other half of exit gate clause 4.
 *
 * P6-05 enforced enrolment: a covered account cannot reach the panel without a
 * working authenticator. That alone does not stop a stolen password, which is
 * the thing a second factor is for — so the code is asked for at sign-in.
 *
 * **The pass lives in the session, never on the user.** A column would make the
 * factor satisfied on every device the moment it was satisfied on one, which is
 * precisely the property a second factor exists to deny.
 */
class TwoFactorChallengeController extends Controller
{
    /** Where the pass is recorded for the current session. */
    public const SESSION_KEY = 'two_factor.passed_at';

    public function show(): View
    {
        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        try {
            $twoFactor->verify($user, $validated['code']);
        } catch (DomainException $e) {
            // Translated into a form error rather than an exception page — the
            // UI layer's job, the same division P0-14 established.
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        // Regenerated before the pass is recorded. A session id that survives
        // the second factor is one an attacker who fixed it beforehand now
        // holds authenticated.
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, now()->toDateTimeString());

        return redirect('/admin');
    }
}
