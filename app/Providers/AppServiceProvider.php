<?php

namespace App\Providers;

use App\Audit\AuthEventSubscriber;
use App\Http\Middleware\EnsureTwoFactorIsConfirmed;
use App\Reminders\Channel;
use App\Reminders\EmailChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Channel::class, EmailChannel::class); // spec 2026-10-17 §7: WhatsApp later

        // The `Guard` contract (alias auth.driver) is what DatabaseSessionHandler reads for sessions.user_id. Pin it to
        // staff, so a portal request (shouldUse('tenant')) never re-owns a staff session row and User::logoutEverywhere()
        // still finds it (tenant portal plan ruling 3). Auth::user() / $request->user() use the auth manager, not this.
        $this->app->singleton('auth.driver', fn ($app) => $app['auth']->guard('web'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Livewire update requests re-run this against the component's original page route.
        Livewire::addPersistentMiddleware([EnsureTwoFactorIsConfirmed::class]);

        Event::subscribe(AuthEventSubscriber::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Spec §8.6: at least 12 characters, in every environment (tests included).
        Password::defaults(fn (): Password => Password::min(12));
    }
}
