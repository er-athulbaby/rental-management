<?php

namespace App\Audit;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEvent;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

class AuthEventSubscriber
{
    private const array USER_EVENTS = [
        Login::class => 'auth.login',
        Logout::class => 'auth.logout',
        PasswordReset::class => 'auth.password.reset',
        TwoFactorAuthenticationEnabled::class => 'auth.2fa.enabled',
        TwoFactorAuthenticationConfirmed::class => 'auth.2fa.confirmed',
        TwoFactorAuthenticationDisabled::class => 'auth.2fa.disabled',
        TwoFactorAuthenticationFailed::class => 'auth.2fa.failed',
        RecoveryCodesGenerated::class => 'auth.2fa.recovery_codes_generated',
    ];

    public function logUserEvent(Login|Logout|PasswordReset|TwoFactorAuthenticationEvent|RecoveryCodesGenerated $event): void
    {
        $user = $event->user instanceof Model ? $event->user : null;

        Audit::log(self::USER_EVENTS[$event::class], $user, causer: $user);
    }

    public function logFailedLogin(Failed $event): void
    {
        // No causer: the attempt is not authenticated. Only the email is kept, never the password.
        activity('audit')->causedByAnonymous()->event('auth.login.failed')
            ->withProperties(['email' => $event->credentials['email'] ?? null])
            ->log('auth.login.failed');
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ...array_fill_keys(array_keys(self::USER_EVENTS), 'logUserEvent'),
            Failed::class => 'logFailedLogin',
        ];
    }
}
