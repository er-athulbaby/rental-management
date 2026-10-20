<?php

namespace App\Actions\Portal;

use App\Mail\PortalSignInCode;
use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use App\Reminders\EmailChannel;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Spec 2026-10-18 tenant portal §3: email a 6-digit sign-in code to an active account. It never says whether the account
 * exists: unknown, disabled and deleted return the same way, inside the same time box (plan ruling 5).
 */
final class SendLoginCode
{
    public const int VALID_MINUTES = 10;

    public function __construct(private readonly Timebox $timebox) {}

    public function handle(mixed $email, string $ip): void
    {
        if (! (new EmailChannel)->ready()) {
            throw ValidationException::withMessages(['email' => __('Portal email is not set up. Please contact the office.')]);
        }

        $email = is_string($email) ? Str::lower(trim($email)) : '';
        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']], ['email.*' => __('Enter your email address.')])->validate();

        // Every request counts, for known and unknown emails alike. Hit first, then compare: parallel requests cannot all slip under the limit.
        foreach (['portal-code-email:'.hash('sha256', $email) => 5, 'portal-code-ip:'.$ip => 20] as $key => $max) {
            if (RateLimiter::hit($key, 3600) > $max) {
                throw ValidationException::withMessages(['email' => __('Too many codes asked for. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
            }
        }

        $this->timebox->call(function () use ($email) {
            $account = PortalAccount::query()->where('email', $email)->first();
            if ($account === null || ! $account->isActive()) {
                return;
            }

            // A fault here is reported, not shown: an error only real accounts could get would tell them apart.
            try {
                $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
                DB::transaction(function () use ($account, $code) {
                    PortalLoginCode::query()->where('portal_account_id', $account->id)->whereNull('used_at')->delete(); // a new code replaces the unused one
                    PortalLoginCode::create([
                        'portal_account_id' => $account->id, 'code_hash' => PortalLoginCode::hash($account, $code), 'expires_at' => now()->addMinutes(self::VALID_MINUTES),
                    ]);
                });

                $tenant = $account->customer;
                Mail::to(new Address($account->email, $tenant->name_en))->queue(new PortalSignInCode($code, $tenant->name_en, $tenant->displayName('ar')));
            } catch (Throwable $e) {
                report($e);
            }
        }, 200_000);
    }
}
