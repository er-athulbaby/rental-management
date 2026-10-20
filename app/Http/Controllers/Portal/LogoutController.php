<?php

namespace App\Http\Controllers\Portal;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Spec 2026-10-18 tenant portal §3: sign out ends the tenant guard only; a staff session in the same browser survives (plan ruling 3). */
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
