<?php

use App\Enums\RoleName;
use App\Livewire\Settings\Security;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function financeUser(bool $withTwoFactor = false): User
{
    $factory = $withTwoFactor ? User::factory()->withTwoFactor() : User::factory();

    return $factory->create()->assignRole(RoleName::Finance);
}

test('sensitive users without confirmed 2FA are sent to 2FA setup', function () {
    $this->actingAs(financeUser());

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->get(route('profile.edit'))->assertRedirect(route('security.edit'));

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Your role requires two-factor authentication');
});

test('the requirement follows a role granted later', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->assignRole(RoleName::Finance);

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
});

test('sensitive users with confirmed 2FA use the app normally', function () {
    $this->actingAs(financeUser(withTwoFactor: true))->get(route('dashboard'))->assertOk();
});

test('Livewire update requests from other pages are guarded too', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
    $snapshot = htmlspecialchars_decode(str($html)->betweenFirst('wire:snapshot="', '"'), ENT_QUOTES);

    $update = function () use ($snapshot) {
        app('livewire')->flushState(); // production gets this for free: one PHP request per update

        return $this->withHeader('X-Livewire', '1')->postJson(app('livewire')->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => ['name' => 'Changed'], 'calls' => [['method' => 'updateProfileInformation', 'params' => []]]]],
        ]);
    };

    $update()->assertOk(); // control: the hand-built update request works

    $user->assignRole(RoleName::Management);

    $update()->assertRedirect(route('security.edit'));
});

test('sensitive users cannot disable confirmed 2FA', function () {
    $user = financeUser(withTwoFactor: true);
    $this->actingAs($user);

    Livewire::test(Security::class)
        ->assertDontSee('Disable 2FA')
        ->call('disable')
        ->assertForbidden();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('two-factor.disable'))
        ->assertForbidden();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

test('another user\'s 2FA can still be reset', function () {
    $user = financeUser(withTwoFactor: true);
    $this->actingAs(User::factory()->create());

    app(DisableTwoFactorAuthentication::class)($user);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('users without sensitive permissions can still disable 2FA', function () {
    $user = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($user);

    Livewire::test(Security::class)->assertSee('Disable 2FA')->call('disable')->assertHasNoErrors();

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('an abandoned, unconfirmed setup is still cleared', function () {
    $user = financeUser();
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => null])->save();
    $this->actingAs($user);

    Livewire::test(Security::class)->assertSet('twoFactorEnabled', false);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});
