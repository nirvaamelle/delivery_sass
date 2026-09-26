<?php

namespace App\Http\Controllers;

use App\Domain\Security\TwoFactorService;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Finish enrolment.
 *
 * The refusal is translated into a form error rather than an exception page —
 * the UI layer's actual job, the same division P0-14 established for the
 * approval-matrix screen.
 */
class TwoFactorConfirmController extends Controller
{
    public function __invoke(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        try {
            $twoFactor->confirm($user, $validated['code']);
        } catch (DomainException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        /*
         * Enrolling satisfies the session's challenge, and this is a fix rather
         * than a shortcut. Confirming proves possession of the authenticator at
         * this moment — and without it a user who has just enrolled is
         * immediately challenged for a code their app will keep showing for up
         * to another thirty seconds, which the replay guard then refuses. The
         * result is an account that cannot get in for half a minute after doing
         * exactly what it was told.
         */
        $request->session()->regenerate();
        $request->session()->put(TwoFactorChallengeController::SESSION_KEY, now()->toDateTimeString());

        return redirect('/admin');
    }
}
