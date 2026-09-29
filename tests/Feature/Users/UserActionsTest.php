<?php

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\ResetUserTwoFactor;
use App\Actions\Users\SyncUserBuildings;
use App\Actions\Users\SyncUserRoles;
use App\Actions\Users\UpdateUserProfile;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\User;
use App\Notifications\SensitiveAccessGranted;
use App\Notifications\UserEmailChanged;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->approver = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
});

function auditEvent(string $event): ?Activity
{
    return Activity::query()->where('event', $event)->latest('id')->first();
}

test('CreateUser creates the user with roles and buildings and sends a reset link, never a password', function () {
    $building = Building::factory()->create();

    $user = app(CreateUser::class)->handle($this->admin, 'Sara Ali', 'sara@demo.test', ['leasing'], [$building->id]);

    expect($user->hasRole(RoleName::Leasing))->toBeTrue()
        ->and($user->buildings()->pluck('buildings.id')->all())->toBe([$building->id])
        ->and(auditEvent('user.created')->subject_id)->toBe($user->id);
    Notification::assertSentTo($user, ResetPassword::class);
});

test('only users.manage holders can create users', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    expect(fn () => app(CreateUser::class)->handle($leasing, 'X', 'x@demo.test', [], []))->toThrow(AuthorizationException::class);
});

test('a user cannot change their own roles', function () {
    expect(fn () => app(SyncUserRoles::class)->handle($this->admin, $this->admin, ['admin', 'finance']))
        ->toThrow(AuthorizationException::class);
});

test('roles changes are audited and sensitive grants notify the other approvers', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);

    app(SyncUserRoles::class)->handle($this->admin, $user, ['leasing', 'finance']);

    expect(auditEvent('user.roles.changed')->attribute_changes->toArray())->toEqual([
        'old' => ['roles' => ['leasing']], 'attributes' => ['roles' => ['finance', 'leasing']],
    ]);
    Notification::assertSentTo($this->approver, SensitiveAccessGranted::class);
    Notification::assertNotSentTo($user, SensitiveAccessGranted::class);
});

test('the Vendor Support role can neither be granted nor removed here', function () {
    $user = User::factory()->create();
    $vendor = User::factory()->create()->assignRole(RoleName::VendorSupport);

    expect(fn () => app(SyncUserRoles::class)->handle($this->admin, $user, ['vendor-support']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SyncUserRoles::class)->handle($this->admin, $vendor, []))->toThrow(AuthorizationException::class);
});

test('an unchanged role list writes no audit row', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);

    app(SyncUserRoles::class)->handle($this->admin, $user, ['leasing']);

    expect(auditEvent('user.roles.changed'))->toBeNull();
});

test('building assignments are audited', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    [$a, $b] = Building::factory()->count(2)->create()->all();

    app(SyncUserBuildings::class)->handle($this->admin, $user, [$a->id, $b->id]);

    expect(auditEvent('user.buildings.changed')->attribute_changes['attributes']['buildings'])->toBe([$a->id, $b->id]);
});

test('an email change notifies the old address and the approvers', function () {
    $user = User::factory()->create(['email' => 'old@demo.test']);

    app(UpdateUserProfile::class)->handle($this->admin, $user, $user->name, 'new@demo.test');

    expect($user->fresh()->email)->toBe('new@demo.test')->and(auditEvent('user.email.changed'))->not->toBeNull();
    Notification::assertSentOnDemand(UserEmailChanged::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@demo.test');
    Notification::assertSentTo($this->approver, UserEmailChanged::class);
});

test('Vendor Support cannot be edited, but can be deactivated and not reactivated', function () {
    $vendor = User::factory()->create()->assignRole(RoleName::VendorSupport);

    expect(fn () => app(UpdateUserProfile::class)->handle($this->admin, $vendor, 'Renamed', $vendor->email))->toThrow(AuthorizationException::class);

    app(DeactivateUser::class)->handle($this->admin, $vendor);
    expect($vendor->fresh()->active)->toBeFalse();

    expect(fn () => app(ReactivateUser::class)->handle($this->admin, $vendor->fresh()))->toThrow(AuthorizationException::class);
});

test('deactivation kills sessions, is audited, and cannot target yourself', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    app(DeactivateUser::class)->handle($this->admin, $user);

    expect($user->fresh()->active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(auditEvent('user.deactivated'))->not->toBeNull();
    expect(fn () => app(DeactivateUser::class)->handle($this->admin, $this->admin))->toThrow(AuthorizationException::class);

    app(ReactivateUser::class)->handle($this->admin, $user->fresh());
    expect($user->fresh()->active)->toBeTrue()->and(auditEvent('user.reactivated'))->not->toBeNull();
});

test('resetting another user\'s 2FA clears it and kills sessions; resetting your own is refused', function () {
    $user = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    DB::table('sessions')->insert(['id' => 's2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $this->actingAs($this->admin);

    app(ResetUserTwoFactor::class)->handle($this->admin, $user);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(auditEvent('user.2fa.reset'))->not->toBeNull();
    expect(fn () => app(ResetUserTwoFactor::class)->handle($this->admin, $this->admin))->toThrow(AuthorizationException::class);
});
