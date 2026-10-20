<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\SetPortalLocale;
use App\Models\PortalAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Spec 2026-10-18 tenant portal §4: switch the portal language; kept on the account when signed in, in the session otherwise. */
final class LocaleController
{
    public function __invoke(Request $request, SetPortalLocale $set): RedirectResponse
    {
        $locale = $request->input('locale');
        if (is_string($locale) && in_array($locale, PortalAccount::LOCALES, true)) {
            $request->session()->put('portal_locale', $locale);
            $account = Auth::guard('tenant')->user();
            if ($account instanceof PortalAccount) {
                $set->handle($account, $locale);
            }
        }

        // Back to the page the switch was on, but only within this app: the Referer header is the browser's word.
        $back = url()->previous();
        $root = url('/');

        return redirect()->to($back === $root || str_starts_with($back, $root.'/') ? $back : route('portal.login'));
    }
}
