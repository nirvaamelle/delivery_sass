<?php

namespace App\Http\Controllers;

use App\Domain\Security\TwoFactorService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAQRCode;

/**
 * Where the enforcement middleware sends somebody who has to enrol.
 *
 * A secret is generated on arrival and shown once, as a QR code and as text —
 * the text matters, because a phone that cannot scan is common and an enrolment
 * page that only offers a picture strands whoever has one.
 */
class TwoFactorSetupController extends Controller
{
    public function __invoke(Request $request, TwoFactorService $twoFactor): View
    {
        /** @var User $user */
        $user = $request->user();

        // Regenerated on each visit while unconfirmed. A secret shown once and
        // then lost is otherwise an account that can never enrol.
        $secret = $twoFactor->isConfirmed($user)
            ? ''
            : $twoFactor->enable($user);

        return view('auth.two-factor-setup', [
            'secret' => $secret,
            'confirmed' => $twoFactor->isConfirmed($user),
            'qr' => $secret === '' ? null : (new Google2FAQRCode)->getQRCodeInline(
                (string) config('app.name'),
                (string) $user->email,
                $secret,
            ),
        ]);
    }
}
