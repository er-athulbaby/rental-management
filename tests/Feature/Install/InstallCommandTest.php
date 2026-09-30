<?php

use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

$options = ['--company' => 'Demo Properties W.L.L.', '--admin-name' => 'Sara Admin', '--admin-email' => 'admin@demo.test', '--vendor-email' => 'support@vendor.test'];

test('it installs everything a new company needs', function () use ($options) {
    Notification::fake();

    $this->artisan('rms:install', $options)
        ->expectsOutputToContain('Vendor Support password:')
        ->expectsOutputToContain('otpauth://totp/')
        ->assertSuccessful();

    expect(CompanySetting::current()->name_en)->toBe('Demo Properties W.L.L.')
        ->and(Role::count())->toBe(6)
        ->and(DB::table('number_sequences')->count())->toBe(16);

    $admin = User::where('email', 'admin@demo.test')->firstOrFail();
    expect($admin->hasRole(RoleName::Admin))->toBeTrue();
    Notification::assertSentTo($admin, ResetPassword::class);

    expect(ContractTemplate::defaultTemplate())->not->toBeNull();

    $vendor = User::where('email', 'support@vendor.test')->firstOrFail();
    expect($vendor->isVendorSupport())->toBeTrue()
        ->and($vendor->two_factor_confirmed_at)->not->toBeNull()
        ->and(decrypt($vendor->two_factor_secret))->toBeString();
});

test('it refuses to run twice', function () use ($options) {
    Notification::fake();
    $this->artisan('rms:install', $options)->assertSuccessful();

    $this->artisan('rms:install', $options)->expectsOutputToContain('already installed')->assertFailed();
});

test('all options are required', function () use ($options) {
    $this->artisan('rms:install', [...$options, '--admin-email' => ''])->assertFailed();
});
