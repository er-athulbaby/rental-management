<?php

use App\Audit\Audit;
use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

function events(): array
{
    return Activity::query()->orderBy('id')->pluck('event')->all();
}

test('a model change over HTTP records ip, user agent and old/new values', function () {
    $user = User::factory()->create(['name' => 'Before']);
    Route::middleware('web')->post('/_test/rename', fn () => tap($user)->update(['name' => 'After']) ? 'ok' : 'no');

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_USER_AGENT' => 'PestUA/1.0'])
        ->post('/_test/rename')->assertOk();

    $row = Activity::query()->where('event', 'updated')->latest('id')->firstOrFail();
    expect($row->ip)->toBe('10.1.2.3')
        ->and($row->user_agent)->toBe('PestUA/1.0')
        ->and($row->attribute_changes['old']['name'])->toBe('Before')
        ->and($row->attribute_changes['attributes']['name'])->toBe('After'); // updated_at may also appear
});

test('secrets never reach the audit log', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'a-new-password-1')
        ->set('password_confirmation', 'a-new-password-1')
        ->call('updatePassword');

    app(EnableTwoFactorAuthentication::class)($user->fresh());
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(DisableTwoFactorAuthentication::class)($user->fresh());
    $user->fresh()->logoutEverywhere();

    $raw = DB::table('activity_log')->get()->map(fn ($row) => json_encode($row))->implode("\n");
    foreach (User::AUDIT_SECRETS as $secret) {
        expect($raw)->not->toContain('"'.$secret.'"');
    }
});

test('login, failed login and logout are logged', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    $failed = Activity::query()->where('event', 'auth.login.failed')->firstOrFail();
    expect($failed->causer_id)->toBeNull()->and($failed->getProperty('email'))->toBe($user->email);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    expect(events())->toContain('auth.login', 'auth.logout');
    expect(Activity::query()->where('event', 'auth.login')->first()->causer_id)->toBe($user->id);
});

test('a password reset and a password change are logged', function () {
    $user = User::factory()->create();

    $this->post(route('password.update'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'twelve-chars-ok',
        'password_confirmation' => 'twelve-chars-ok',
    ]);

    $this->actingAs($user->fresh());
    Livewire::test(Security::class)
        ->set('current_password', 'twelve-chars-ok')
        ->set('password', 'a-new-password-1')
        ->set('password_confirmation', 'a-new-password-1')
        ->call('updatePassword');

    expect(events())->toContain('auth.password.reset', 'auth.password.changed');
});

test('2FA enable, confirm, disable and a failed challenge are logged', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(EnableTwoFactorAuthentication::class)($user);
    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    app(ConfirmTwoFactorAuthentication::class)($user->fresh(), $code);
    app(DisableTwoFactorAuthentication::class)($user->fresh());

    auth()->logout();
    // The factory secret is not valid base32; a failed challenge needs a real one.
    $withTwoFactor = User::factory()->withTwoFactor()->create([
        'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
    ]);
    $this->post(route('login.store'), ['email' => $withTwoFactor->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => '000000']);

    expect(events())->toContain('auth.2fa.enabled', 'auth.2fa.confirmed', 'auth.2fa.disabled', 'auth.2fa.failed');
});

test('Audit::log stores old and new like model rows do', function () {
    $user = User::factory()->create();

    $row = Audit::log('user.roles.changed', $user, ['roles' => ['leasing']], ['roles' => ['finance', 'leasing']], ['note' => 'x']);

    expect($row->log_name)->toBe('audit')
        ->and($row->attribute_changes->toArray())->toEqual(['old' => ['roles' => ['leasing']], 'attributes' => ['roles' => ['finance', 'leasing']]])
        ->and($row->getProperty('note'))->toBe('x');
});

test('the audit log cannot be updated or deleted', function () {
    $row = Audit::log('test.event');

    expect(fn () => DB::table('activity_log')->where('id', $row->id)->update(['event' => 'x']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo)->toBe(['45000', 1644, 'activity_log is append-only']));
    expect(fn () => DB::table('activity_log')->where('id', $row->id)->delete())
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo)->toBe(['45000', 1644, 'activity_log is append-only']));
});

test('the app user cannot drop the audit triggers', function () {
    // Probe on a separate connection: a denied DDL statement commits the RefreshDatabase transaction.
    config(['database.connections.grants_probe' => config('database.connections.mysql')]);

    expect(fn () => DB::connection('grants_probe')->unprepared('DROP TRIGGER activity_log_no_update'))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1142));

    DB::purge('grants_probe');
})->skip(
    fn () => config('database.connections.mysql.username') === config('database.connections.migrator.username'),
    'app and migrator are the same user',
);
