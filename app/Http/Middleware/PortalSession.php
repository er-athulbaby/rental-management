<?php

namespace App\Http\Middleware;

use App\Models\PortalAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 2026-10-18 tenant portal §3 and §4, on every portal request and (persistent) every portal Livewire update: the page
 * speaks the account's language, or the guest's choice; a disabled account or a deleted tenant ends the tenant session
 * (plan rulings 2 and 16). Uses session() rather than $request->session(): Livewire re-runs this on a copied request.
 */
final class PortalSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('tenant');
        $account = $guard->user();

        $locale = $account instanceof PortalAccount ? $account->locale : session('portal_locale');
        App::setLocale(in_array($locale, PortalAccount::LOCALES, true) ? $locale : 'en');

        if ($account instanceof PortalAccount && ! $account->isActive()) {
            $guard->logout(); // the tenant guard only (plan ruling 3)
            session()->regenerateToken();

            return redirect()->route('portal.login')->with('status', __('Your portal access has ended. Please contact the office.'));
        }

        return $next($request);
    }
}
