<?php

namespace App\Providers;

use App\Http\Middleware\EnsureTwoFactorIsConfirmed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Livewire update requests re-run this against the component's original page route.
        Livewire::addPersistentMiddleware([EnsureTwoFactorIsConfirmed::class]);
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
