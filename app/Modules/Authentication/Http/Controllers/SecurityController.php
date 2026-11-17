<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Fortify;

class SecurityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $status = session('status');

        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        // Recovery codes are displayed exactly once: immediately after
        // enrolment is confirmed or the codes are regenerated.
        $showRecoveryCodes = in_array($status, [
            Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED,
            Fortify::RECOVERY_CODES_GENERATED,
        ], true) && $user->hasTwoFactorEnabled();

        return view('account.security', [
            'user' => $user,
            'status' => $status,
            'pending' => $pending,
            'qrCode' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'recoveryCodes' => $showRecoveryCodes ? $user->recoveryCodes() : [],
            'required' => config('wcc.security.enforce_two_factor') && $user->isPrivileged(),
        ]);
    }
}
