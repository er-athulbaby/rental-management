<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->vendor = User::factory()->withTwoFactor()->create()->assignRole(RoleName::VendorSupport);
});

test('disable deactivates, kills sessions and audits', function () {
    DB::table('sessions')->insert(['id' => 'v1', 'user_id' => $this->vendor->id, 'payload' => '', 'last_activity' => time()]);

    $this->artisan('rms:vendor-support', ['--disable' => true])->assertSuccessful();

    expect($this->vendor->fresh()->active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $this->vendor->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'vendor_support.disabled')->exists())->toBeTrue();
});

test('enable reactivates and audits', function () {
    $this->vendor->forceFill(['active' => false])->save();

    $this->artisan('rms:vendor-support', ['--enable' => true])->assertSuccessful();

    expect($this->vendor->fresh()->active)->toBeTrue()
        ->and(Activity::query()->where('event', 'vendor_support.enabled')->exists())->toBeTrue();
});

test('exactly one flag is required', function () {
    $this->artisan('rms:vendor-support')->assertFailed();
    $this->artisan('rms:vendor-support', ['--enable' => true, '--disable' => true])->assertFailed();
});
