<?php

use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

function attemptLogin(string $email, string $password = 'password', string $ip = '127.0.0.1', array $extra = [])
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $email, 'password' => $password, ...$extra]);
}

test('inactive users cannot log in', function () {
    $user = User::factory()->inactive()->create();

    attemptLogin($user->email)->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a user deactivated mid-session is logged out on the next request', function () {
    $user = User::factory()->create();
    attemptLogin($user->email)->assertRedirect(route('dashboard', absolute: false));

    User::whereKey($user->id)->update(['active' => false]);
    auth()->forgetGuards(); // a real next request builds a fresh guard

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('login never sets a remember cookie, even when remember=on is posted', function () {
    $user = User::factory()->create();

    $response = attemptLogin($user->email, extra: ['remember' => 'on']);

    $this->assertAuthenticated();
    expect(collect($response->headers->getCookies())->map->getName()
        ->filter(fn (string $name) => str_starts_with($name, 'remember_')))->toBeEmpty();
});

test('the 2FA challenge never remembers either', function () {
    $user = User::factory()->withTwoFactor()->create();

    attemptLogin($user->email, extra: ['remember' => 'on'])->assertRedirect(route('two-factor.login'));

    expect(session('login.remember'))->toBeFalse();
});

test('login is limited to 5 attempts per minute per email and IP', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        attemptLogin($user->email, 'wrong-password')->assertSessionHasErrors('email');
    }

    attemptLogin($user->email)->assertStatus(429);
    attemptLogin($user->email, ip: '10.0.0.9')->assertRedirect(route('dashboard', absolute: false));
});

test('20 failures per hour per email block that email from any IP', function () {
    $user = User::factory()->create();

    foreach (range(1, 20) as $i) {
        attemptLogin($user->email, 'wrong-password', ip: "10.0.1.$i")->assertSessionHasErrors('email');
    }

    attemptLogin($user->email, ip: '10.0.2.1')->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(61)->minutes();

    attemptLogin($user->email, ip: '10.0.2.1')->assertRedirect(route('dashboard', absolute: false));
});

test('deactivation deletes live database sessions and rotates remember_token', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    attemptLogin($user->email)->assertRedirect(route('dashboard', absolute: false));
    $cookie = [config('session.cookie') => session()->getId()];

    // Simulate a brand-new request: fresh session store and guard, only the cookie survives.
    $fresh = function () use ($cookie) {
        app('session')->forgetDrivers();
        auth()->forgetGuards();

        return $this->withCookies($cookie)->get(route('dashboard'));
    };

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
    $fresh()->assertOk();

    $token = $user->fresh()->remember_token;
    $user->forceFill(['active' => false])->save();
    $user->logoutEverywhere();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe($token);
    $fresh()->assertRedirect(route('login'));
});

test('a password reset deletes sessions and rotates remember_token', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $token = $user->remember_token;

    $this->post(route('password.update'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'twelve-chars-ok',
        'password_confirmation' => 'twelve-chars-ok',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->remember_token)->not->toBe($token);
});

test('inactive users get no reset link and cannot reset', function () {
    Notification::fake();
    $user = User::factory()->inactive()->create();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
    Notification::assertNothingSent();

    $this->post(route('password.update'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'twelve-chars-ok',
        'password_confirmation' => 'twelve-chars-ok',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('a password change logs out the user\'s other sessions', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $this->actingAs($user);

    Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'a-new-password-1')
        ->set('password_confirmation', 'a-new-password-1')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse();
});

test('passwords must be at least 12 characters', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'elevenchars')
        ->set('password_confirmation', 'elevenchars')
        ->call('updatePassword')
        ->assertHasErrors(['password' => 'The password field must be at least 12 characters.']);
});

test('sessions idle out after 30 minutes and use secure cookies', function () {
    expect(config('session.lifetime'))->toBe(30)
        ->and(config('session.secure'))->toBeTrue()
        ->and(config('session.expire_on_close'))->toBeFalse();
});
