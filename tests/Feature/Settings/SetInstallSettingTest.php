<?php

use App\Models\CompanySetting;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => CompanySetting::factory()->create());

test('it switches the different-approver rule and audits it', function () {
    $this->artisan('rms:setting', ['key' => 'require_different_approver', 'value' => 'false'])->assertSuccessful();

    expect(CompanySetting::current()->require_different_approver)->toBeFalse();

    $row = Activity::query()->where('event', 'settings.install_level.changed')->firstOrFail();
    expect($row->attribute_changes->toArray())->toEqual([
        'old' => ['require_different_approver' => true],
        'attributes' => ['require_different_approver' => false],
    ]);
});

test('it sets and clears the go-live date', function () {
    $this->artisan('rms:setting', ['key' => 'go_live_at', 'value' => '2026-12-01'])->assertSuccessful();
    expect(CompanySetting::current()->go_live_at->toDateString())->toBe('2026-12-01');

    $this->artisan('rms:setting', ['key' => 'go_live_at', 'value' => 'null'])->assertSuccessful();
    expect(CompanySetting::current()->go_live_at)->toBeNull();
});

test('it rejects unknown keys and bad values', function () {
    $this->artisan('rms:setting', ['key' => 'vat_rate', 'value' => '5'])->assertFailed();
    $this->artisan('rms:setting', ['key' => 'require_different_approver', 'value' => 'maybe'])->assertFailed();
    $this->artisan('rms:setting', ['key' => 'go_live_at', 'value' => 'soon'])->assertFailed();
});
