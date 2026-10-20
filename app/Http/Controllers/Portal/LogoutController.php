<?php

namespace App\Http\Controllers\Portal;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Spec 2026-10-18 tenant portal §3: sign out ends the tenant guard only, and forgets its remember cookie; a staff session
 * in the same browser survives (plan ruling 3). The reverse is not symmetric: staff sign-out (Fortify) invalidates the
 * whole session, but a tenant who chose "remember this device" signs straight back in from that cookie. Accepted: the
 * guards are independent (spec §6).
 */
final class LogoutController
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('tenant')->logout(); // audited portal.logout by AuthEventSubscriber
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
