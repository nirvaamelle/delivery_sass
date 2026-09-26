<?php

namespace App\Http\Middleware;

use App\Domain\Security\TwoFactorService;
use App\Http\Controllers\TwoFactorChallengeController;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exit gate clause 4's enforcement — the word the clause actually uses.
 *
 * Middleware rather than a check on each screen, for the same reason P6-01's
 * row scope is a query scope: a rule applied per resource is one somebody
 * forgets on the resource that matters, and here that is whichever resource
 * gets built next.
 *
 * Two states, in this order, and the order is the point.
 *
 * **Not enrolled → setup.** Challenging somebody who holds no authenticator
 * asks them for a code nothing can produce: a locked door with no key rather
 * than a second factor.
 *
 * **Enrolled but not challenged this session → challenge.** P6-05 stopped at
 * enrolment, which does not stop a stolen password. Once per session rather
 * than once per request: a challenge on every page load is one people route
 * around, and what is being authenticated is the session.
 *
 * Accounts the gate does not name pass straight through. Widening this would
 * lock a timekeeper out of the system over a rule nobody agreed to.
 */
class RequireTwoFactor
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Switched off for now (config/security.php). Everything below stays
        // built; enforcing it again is TWO_FACTOR_ENABLED=true.
        if (! config('security.two_factor_enabled')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $this->twoFactor->isRequiredFor($user)) {
            return $next($request);
        }

        // The setup and challenge pages are exempt, or each redirect loops onto
        // itself.
        if ($request->routeIs('two-factor.*')) {
            return $next($request);
        }

        if (! $this->twoFactor->isConfirmed($user)) {
            return redirect()->route('two-factor.setup');
        }

        if ($request->session()->get(TwoFactorChallengeController::SESSION_KEY) === null) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
