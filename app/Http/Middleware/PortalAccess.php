<?php

namespace App\Http\Middleware;

use App\Models\PortalAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 2026-10-21 owner portal §3: tenant pages need an active tenant on the account, owner pages an active owner;
 * anything else is a 404. The side a page belongs to is kept in the session for the landing (owner portal plan
 * rulings 1, 3 and 4). Runs after auth:tenant, so the guard's user is a portal account.
 */
final class PortalAccess
{
    public const string VIEW = 'portal_view';

    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $account = Auth::guard('tenant')->user();
        abort_unless($account instanceof PortalAccount, 404);

        if (! ($kind === 'owner' ? $account->hasOwner() : $account->hasTenant())) {
            // /portal is the portal's front door: an owner-only account goes to its own home rather than a 404.
            if ($kind === 'tenant' && $request->routeIs('portal.home') && $account->hasOwner()) {
                return redirect()->route('portal.owner.home');
            }
            abort(404);
        }

        session([self::VIEW => $kind]);

        return $next($request);
    }

    /** Where a signed-in account lands: the side it last used if it still has it, else its tenant side, else its owner side. */
    public static function homeRoute(mixed $account): string
    {
        if (! $account instanceof PortalAccount) {
            return 'portal.home';
        }
        if (session(self::VIEW) === 'owner' && $account->hasOwner()) {
            return 'portal.owner.home';
        }

        return $account->hasTenant() ? 'portal.home' : 'portal.owner.home';
    }
}
