<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorIsConfirmed
{
    /**
     * Routes a user without confirmed 2FA still needs in order to set it up (or leave).
     * Livewire's update endpoint is exempt because Livewire re-runs this middleware
     * against the component's original page route (persistent middleware).
     */
    private const array EXEMPT_ROUTES = [
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.*',
        'logout',
        '*livewire.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user
            && is_null($user->two_factor_confirmed_at)
            && ! $request->routeIs(...self::EXEMPT_ROUTES)
            && $user->requiresTwoFactor()) {
            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
