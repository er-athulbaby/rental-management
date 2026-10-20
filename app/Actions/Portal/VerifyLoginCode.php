<?php

namespace App\Actions\Portal;

use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §3: a code is valid 10 minutes, once, for 5 tries. Returns the account it signs in, or
 * null for every wrong, spent, expired or unknown combination alike. Signing in is the screen's job (Task 3).
 */
final class VerifyLoginCode
{
    public const int MAX_ATTEMPTS = 5;

    public function handle(mixed $email, mixed $code, string $ip): ?PortalAccount
    {
        $key = 'portal-verify-ip:'.$ip;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['code' => __('Too many tries. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        RateLimiter::hit($key, 60);

        $email = is_string($email) ? Str::lower(trim($email)) : '';
        $code = is_string($code) ? trim($code) : '';
        if ($email === '' || preg_match('/^\d{6}$/', $code) !== 1) {
            return null; // malformed: no attempt is spent
        }

        return DB::transaction(function () use ($email, $code): ?PortalAccount {
            $account = PortalAccount::query()->where('email', $email)->first();
            if ($account === null || ! $account->isActive()) {
                return null;
            }

            $row = PortalLoginCode::query()->where('portal_account_id', $account->id)->whereNull('used_at')
                ->where('expires_at', '>', now())->where('attempts', '<', self::MAX_ATTEMPTS)
                ->latest('id')->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }

            $row->increment('attempts'); // the 5th wrong try spends the code
            if (! hash_equals($row->code_hash, PortalLoginCode::hash($account, $code))) {
                return null;
            }

            $row->forceFill(['used_at' => now()])->save();
            $account->forceFill(['last_login_at' => now()])->save();

            return $account;
        });
    }
}
