<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // Outside the web group: no session row per uptime ping.
            Route::get('/health', function () {
                DB::select('select 1');

                return response()->json(['status' => 'up', 'version' => config('app.version')]);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Reset links and redirects are built only for our own host (spec §8.6).
        $middleware->trustHosts(at: fn () => [parse_url((string) config('app.url'), PHP_URL_HOST)], subdomains: false);

        // Every web request, including Livewire's update endpoint and Fortify's routes.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\EnsureTwoFactorIsConfirmed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
