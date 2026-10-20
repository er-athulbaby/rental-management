<?php

use App\Enums\RoleName;
use App\Livewire\Portal\Home;
use App\Livewire\Portal\Login;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'sara@tenant.test']);
    $this->account = portalAccount($this->tenant);
});

test('the sign-in screen sends a code, signs in on the tenant guard, and remembers the device for 30 days by default', function () {
    $page = Livewire::test(Login::class)->set('email', 'sara@tenant.test')->call('sendCode')
        ->assertHasNoErrors()->assertSee("If this email has an account, we've sent a code.");
    $page->set('code', portalCodeFor('sara@tenant.test'))->call('signIn')->assertHasNoErrors()->assertRedirect(route('portal.home'));

    expect(Auth::guard('tenant')->user()?->is($this->account))->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();
    $cookie = Cookie::queued(Auth::guard('tenant')->getRecallerName());
    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBe(now()->addMinutes(43200)->getTimestamp());

    $login = Activity::query()->where('event', 'portal.login')->sole();
    expect($login->causer?->is($this->account))->toBeTrue()->and($login->subject?->is($this->account))->toBeTrue();
});

test('without "remember this device" no remember cookie is set', function () {
    Livewire::test(Login::class)->set('email', 'sara@tenant.test')->call('sendCode')
        ->set('code', portalCodeFor('sara@tenant.test'))->set('remember', false)->call('signIn')->assertRedirect(route('portal.home'));

    expect(Auth::guard('tenant')->check())->toBeTrue()
        ->and(Cookie::queued(Auth::guard('tenant')->getRecallerName()))->toBeNull();
});

test('an unknown email sees the same screen, and a wrong code signs nobody in', function () {
    Livewire::test(Login::class)->set('email', 'nobody@tenant.test')->call('sendCode')
        ->assertHasNoErrors()->assertSee("If this email has an account, we've sent a code.")
        ->set('code', '123456')->call('signIn')->assertHasErrors('code');

    Mail::assertNothingQueued();
    expect(Auth::guard('tenant')->check())->toBeFalse();
});

test('tampered sign-in input is a message, never a 500', function (string $property, mixed $value, string $step) {
    $page = Livewire::test(Login::class);
    if ($step === 'signIn') {
        $page->set('email', 'sara@tenant.test')->call('sendCode');
    }

    $page->set($property, $value)->call($step)->assertOk()->assertHasErrors($step === 'sendCode' ? 'email' : 'code');
    expect(Auth::guard('tenant')->check())->toBeFalse();
})->with([
    'email as an array' => ['email', ['x'], 'sendCode'],
    'code as an array' => ['code', ['123456'], 'signIn'],
    'remember as an array with a bad code' => ['remember', ['x'], 'signIn'],
]);

test('while portal email is not set up the screen says so and offers no form', function () {
    config(['mail.default' => 'log']);

    Livewire::test(Login::class)->assertSee('Portal email is not set up. Please contact the office.')->assertDontSee('Send code');
});

test('portal pages send guests to the portal sign-in, and signed-in tenants skip it', function () {
    $this->get(route('portal.home'))->assertRedirect(route('portal.login'));
    $this->get(route('portal.login'))->assertOk()->assertSee('Sign in to the tenant portal');

    $this->actingAs($this->account, 'tenant');
    $this->get(route('portal.login'))->assertRedirect(route('portal.home'));
    $this->get(route('portal.home'))->assertOk()->assertSee('Welcome, Sara Tenant');
});

test('a staff session does not open the portal, and staff without 2FA can still reach the portal sign-in', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $finance = User::factory()->create()->assignRole(RoleName::Finance); // needs 2FA, has none

    $this->actingAs($finance)->get(route('portal.home'))->assertRedirect(route('portal.login'));
    $this->get(route('portal.login'))->assertOk();
});

test('disabling the account or deleting the tenant ends the session on the next request', function (Closure $end) {
    $this->actingAs($this->account, 'tenant')->get(route('portal.home'))->assertOk();
    $end->call($this);

    $this->get(route('portal.home'))->assertRedirect(route('portal.login'))->assertSessionHas('status');
    expect(Auth::guard('tenant')->check())->toBeFalse();
    $this->get(route('portal.login'))->assertSee('Your portal access has ended. Please contact the office.');
})->with([
    'disabled' => [fn () => $this->account->forceFill(['disabled_at' => now()])->save()],
    'tenant deleted' => [fn () => $this->tenant->delete()],
]);

test('portal components refuse a disabled account themselves (Livewire tests run no middleware)', function () {
    $this->account->forceFill(['disabled_at' => now()])->save();

    Livewire::actingAs($this->account, 'tenant')->test(Home::class)->assertForbidden();
});

test('signing out ends only the tenant session and is audited', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff)->actingAs($this->account, 'tenant');

    $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));

    expect(Auth::guard('tenant')->check())->toBeFalse()
        ->and(Auth::guard('web')->user()?->is($staff))->toBeTrue()
        ->and(Activity::query()->where('event', 'portal.logout')->sole()->causer?->is($this->account))->toBeTrue();
    $this->get(route('profile.edit'))->assertOk();
});

test('the tenant switches to Arabic: the page turns right to left and the choice stays on the account', function () {
    $this->actingAs($this->account, 'tenant');

    $this->post(route('portal.locale'), ['locale' => 'ar'])->assertRedirect();
    expect($this->account->fresh()->locale)->toBe('ar');
    $this->get(route('portal.home'))->assertOk()
        ->assertSee('<html lang="ar" dir="rtl">', false)
        ->assertSee('أهلاً، سارة')
        ->assertSee('English');

    $this->post(route('portal.locale'), ['locale' => 'fr'])->assertRedirect(); // ignored
    expect($this->account->fresh()->locale)->toBe('ar');
});

test('a guest can read the sign-in screen in Arabic', function () {
    $this->post(route('portal.locale'), ['locale' => 'ar'])->assertRedirect();

    $this->get(route('portal.login'))->assertOk()->assertSee('dir="rtl"', false)->assertSee('تسجيل الدخول إلى بوابة المستأجر');
});

// Carried in from the Task 1 review (plan ruling 1, M2, M5).

test('staff guests go to the staff sign-in, and signed-in staff skip it', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect(route('dashboard'));
});

test('tenant sign-in and sign-out are portal events, never staff auth events, and staff ones are unchanged', function () {
    Auth::guard('tenant')->login($this->account);
    Auth::guard('tenant')->logout();
    expect(Activity::query()->where('event', 'like', 'auth.%')->exists())->toBeFalse()
        ->and(Activity::query()->whereIn('event', ['portal.login', 'portal.logout'])->pluck('event')->all())->toBe(['portal.login', 'portal.logout']);

    $staff = User::factory()->create();
    Auth::guard('web')->login($staff);
    expect(Activity::query()->where('event', 'auth.login')->sole()->causer?->is($staff))->toBeTrue();
});

test('portal views never use @can: the policies take staff Users and would fail on a portal account', function () {
    $views = [resource_path('views/layouts/portal.blade.php'), ...glob(resource_path('views/livewire/portal/*.blade.php'))];

    expect(array_filter($views, fn (string $v) => preg_match('/@can|@cannot|->can\(/', (string) file_get_contents($v)) === 1))->toBe([]);
});

/** The first wire:snapshot on a page, checked to be the named component's. */
function pageSnapshot(string $html, string $component): string
{
    $snapshot = htmlspecialchars_decode(str($html)->betweenFirst('wire:snapshot="', '"'), ENT_QUOTES);
    expect(json_decode($snapshot, true)['memo']['name'])->toBe($component);

    return $snapshot;
}

test('a staff component updated after a portal component in the same request still sees the staff user', function () {
    // Livewire applies persistent middleware once per route per request (PersistentMiddleware.php:109), so a bundle of
    // staff, portal, staff would otherwise leave the third snapshot on the tenant guard (Task 1 review M2).
    $staff = User::factory()->create();
    $this->actingAs($staff)->actingAs($this->account, 'tenant');
    $dashboard = pageSnapshot($this->get(route('dashboard'))->assertOk()->getContent(), 'dashboard');
    $home = pageSnapshot($this->get(route('portal.home'))->assertOk()->getContent(), 'portal.home');
    $refresh = fn (string $snapshot) => ['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => []]]];
    app('livewire')->flushState(); // production gets this for free: one PHP request per update

    $this->withHeader('X-Livewire', '1')->postJson(app('livewire')->getUpdateUri(), [
        'components' => [$refresh($dashboard), $refresh($home), $refresh($dashboard)],
    ])->assertOk();
});

test('a portal session can never update a staff component', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff)->actingAs($this->account, 'tenant');
    $dashboard = pageSnapshot($this->get(route('dashboard'))->assertOk()->getContent(), 'dashboard');
    $home = pageSnapshot($this->get(route('portal.home'))->assertOk()->getContent(), 'portal.home');
    $refresh = fn (string $snapshot) => ['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => []]]];
    Auth::guard('web')->logout(); // the tenant session alone remains

    foreach ([[$dashboard], [$home, $dashboard]] as $components) {
        app('livewire')->flushState();
        $this->withHeader('X-Livewire', '1')->postJson(app('livewire')->getUpdateUri(), ['components' => array_map($refresh, $components)])
            ->assertUnauthorized();
    }
    expect(Auth::guard('tenant')->user()?->is($this->account))->toBeTrue();
});
