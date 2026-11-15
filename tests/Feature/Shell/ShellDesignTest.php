<?php

use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('the sidebar opens the group holding the current page and leaves the others closed', function () {
    CompanySetting::factory()->create();
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    $this->actingAs($finance)->get(route('invoices.index'))->assertOk()
        ->assertSee('data-nav-group="finance" data-current open', false)
        ->assertSee('data-nav-group="leasing"', false)
        ->assertDontSee('data-nav-group="leasing" data-current', false);

    $this->actingAs($finance)->get(route('ledger.index'))->assertOk()
        ->assertSee('data-nav-group="ledger" data-current open', false)
        ->assertDontSee('data-nav-group="finance" data-current', false);
});

test('groups and items still follow permissions', function () {
    CompanySetting::factory()->create();
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($leasing)->get(route('dashboard'))->assertOk()
        ->assertSee('data-nav-group="leasing"', false)
        ->assertDontSee('data-nav-group="finance"', false)
        ->assertDontSee('data-nav-group="ledger"', false)
        ->assertDontSee('data-nav-group="administration"', false)
        ->assertDontSee(route('invoices.index'));
});

test('the sidebar shows the company name, and the logo once one is uploaded', function () {
    Storage::fake('local');
    CompanySetting::factory()->create(['name_en' => 'Gulf Homes W.L.L.']);
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($leasing)->get(route('dashboard'))->assertOk()
        ->assertSee('Gulf Homes W.L.L.')->assertDontSee(route('brand.logo'));

    Storage::disk('local')->put('settings/logo.png', 'png-bytes');
    CompanySetting::query()->whereKey(1)->update(['logo_path' => 'settings/logo.png']);

    $this->actingAs($leasing)->get(route('dashboard'))->assertOk()->assertSee(route('brand.logo'), false);
});

test('brand() names the company in Arabic for Arabic pages and falls back to the app name without settings', function () {
    expect(CompanySetting::brand())->toBe(['name' => config('app.name'), 'logo' => null]);

    CompanySetting::factory()->create(['name_en' => 'Gulf Homes', 'name_ar' => 'بيوت الخليج']);
    app()->setLocale('ar');

    expect(CompanySetting::brand()['name'])->toBe('بيوت الخليج');
});

test('the logo route serves the uploaded logo to anyone, and 404s without one', function () {
    Storage::fake('local');
    CompanySetting::factory()->create();

    $this->get(route('brand.logo'))->assertNotFound();

    Storage::disk('local')->put('settings/logo.png', 'png-bytes');
    CompanySetting::query()->whereKey(1)->update(['logo_path' => 'settings/logo.png']);

    $response = $this->get(route('brand.logo'))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('max-age=86400')->toContain('public');
});
