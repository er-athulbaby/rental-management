<?php

namespace App\Actions\Portal;

use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-18 tenant portal §3: a code is valid 10 minutes, once, for 5 tries. Returns the account it signs in, or
 * null for every wrong, spent, expired or unknown combination alike, inside the same time box as SendLoginCode, so the
 * time a check takes does not say whether the account exists. Signing in is the screen's job (Task 3).
 */
final class VerifyLoginCode
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(private readonly Timebox $timebox) {}

    public function handle(mixed $email, mixed $code, string $ip): ?PortalAccount
    {
        $email = is_string($email) ? Str::lower(trim($email)) : '';
        $code = is_string($code) ? trim($code) : '';

        // Hit first, then compare. 10 per IP per minute; 10 per email per 10 minutes (known or not), so rotating IPs
        // cannot sample one email's timing without bound.
        $limits = ['portal-verify-ip:'.$ip => [10, 60]];
        if ($email !== '') {
            $limits['portal-verify-email:'.hash('sha256', $email)] = [10, 600];
        }
        foreach ($limits as $key => [$max, $decay]) {
            if (RateLimiter::hit($key, $decay) > $max) {
                throw ValidationException::withMessages(['code' => __('Too many tries. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
            }
        }

        if ($email === '' || preg_match('/^\d{6}$/', $code) !== 1) {
            return null; // malformed: no attempt is spent
        }

        return $this->timebox->call(fn (): ?PortalAccount => DB::transaction(function () use ($email, $code): ?PortalAccount {
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
        }), 200_000);
    }
}
