<?php

namespace App\Providers;

use App\Audit\AuthEventSubscriber;
use App\Http\Middleware\EnsureTwoFactorIsConfirmed;
use App\Http\Middleware\PortalSession;
use App\Ledger\Poster;
use App\Models\MailSetting;
use App\Reminders\Channel;
use App\Reminders\EmailChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

use function Livewire\on;

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

        // Livewire update requests re-run these against the component's original page route.
        Livewire::addPersistentMiddleware([EnsureTwoFactorIsConfirmed::class, PortalSession::class]);

        // Livewire applies persistent middleware once per route per update request (PersistentMiddleware.php:109), so in a
        // bundle of staff, portal, staff snapshots the third would keep the tenant guard as the default. Each snapshot's
        // own page, matched to its route (the signed path is raw: `/%70ortal`), decides the default guard; its auth
        // middleware already passed for that route (tenant portal plan ruling 1).
        on('snapshot-verified', function (array $snapshot): void {
            if (app(HandleRequests::class)->isLivewireRoute()) {
                $portal = PortalSession::isPortalPage((string) ($snapshot['memo']['path'] ?? ''), (string) ($snapshot['memo']['method'] ?? 'GET'));
                Auth::shouldUse($portal ? 'tenant' : 'web');
            }
        });

        Event::subscribe(AuthEventSubscriber::class);

        // General ledger plan ruling 1: money records post to the ledger after their own transaction commits.
        Poster::observe();

        // Email settings saved on screen: this request, and each queued job (a worker never restarts for them).
        MailSetting::apply();
        Queue::before(fn () => MailSetting::apply());
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
