<?php

use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
});

test('health reports up and the version without starting a session', function () {
    config(['app.version' => 'v1.2.3']);

    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'up', 'version' => 'v1.2.3'])
        ->assertCookieMissing(config('session.cookie'));
});

test('the sidebar shows the version', function () {
    config(['app.version' => 'v9.9.9']);

    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))
        ->get(route('dashboard'))->assertOk()->assertSee('v9.9.9');
});

test('administration links appear only for their permission', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))
        ->get(route('dashboard'))
        ->assertDontSee(route('admin.users.index'))
        ->assertDontSee(route('admin.audit'));

    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin))
        ->get(route('dashboard'))
        ->assertSee(route('admin.users.index'))
        ->assertSee(route('admin.roles.index'))
        ->assertSee(route('admin.settings'))
        ->assertSee(route('admin.audit'));

    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management))
        ->get(route('dashboard'))
        ->assertSee(route('admin.audit'))
        ->assertDontSee(route('admin.users.index'));
});

test('errors show a friendly message and no details when debug is off', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('secret SQL detail'));

    $this->get('/_test/boom')
        ->assertStatus(500)
        ->assertSee('Something went wrong. Please try again.')
        ->assertDontSee('secret SQL detail');
});

test('Sentry privacy settings are hard-coded', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.max_request_body_size'))->toBe('never')
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tracing.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tags'))->toHaveKey('company');
});

test('only the APP_URL host is trusted', function () {
    // TrustHosts itself is skipped while running unit tests (shouldSpecifyTrustedHosts), so check its host list.
    config(['app.url' => 'https://rms.test']);

    $hosts = app(TrustHosts::class)->hosts();

    expect($hosts)->toBe(['rms.test']);
});

test('the sidebar credits the developer with a link', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))
        ->get(route('dashboard'))->assertOk()->assertSee('Developed by')->assertSee('DeVerra Technologies')->assertSee('href="https://devera.me"', false);
});
