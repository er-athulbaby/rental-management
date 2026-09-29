<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Used by the Security component AND Fortify's DELETE /user/two-factor-authentication route.
        $this->app->bind(
            \Laravel\Fortify\Actions\DisableTwoFactorAuthentication::class,
            \App\Actions\Fortify\DisableTwoFactorAuthentication::class,
        );
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('livewire.auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('livewire.auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('livewire.auth.confirm-password'));
        Fortify::resetPasswordView(fn () => view('livewire.auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('livewire.auth.forgot-password'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // 5 attempts per minute per email + IP (every POST /login counts).
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }

    /**
     * Active users only, no "remember me", and 20 failures per hour per email from any IP (spec §8.6).
     * Fortify calls this up to twice per successful login, so it must stay side-effect free on success.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            // Fortify reads $request->boolean('remember') after this callback, for both
            // guard->login() and the 2FA challenge's session('login.remember').
            $request->merge(['remember' => false]);

            $email = Str::lower((string) $request->input(Fortify::username()));
            $failuresKey = 'login-failures:'.$email;

            if (RateLimiter::tooManyAttempts($failuresKey, 20)) {
                $seconds = RateLimiter::availableIn($failuresKey);

                throw ValidationException::withMessages([
                    Fortify::username() => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
                ]);
            }

            // Same 200 ms timebox Fortify uses, so unknown emails aren't faster than wrong passwords.
            $user = (new Timebox)->call(function () use ($request, $email) {
                $user = User::where('email', $email)->first();

                return $user?->active && Hash::check((string) $request->input('password'), $user->password)
                    ? $user
                    : null;
            }, 200_000);

            if (! $user) {
                RateLimiter::hit($failuresKey, 3600);
            }

            // ponytail: replacing Fortify's check drops its rehash-on-login; add rehashPasswordIfRequired() if the hash cost changes.
            return $user;
        });
    }
}
