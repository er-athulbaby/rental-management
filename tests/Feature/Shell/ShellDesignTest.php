<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;

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
    Once::flush(); // a query-builder update fires no saved event

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
    Once::flush(); // a query-builder update fires no saved event

    $response = $this->get(route('brand.logo'))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('max-age=86400')->toContain('public');
});

test('the logo route works for staff who still must set up two-factor, and sets no cookie', function () {
    Storage::fake('local');
    CompanySetting::factory()->create();
    Storage::disk('local')->put('settings/logo.png', 'png-bytes');
    CompanySetting::query()->whereKey(1)->update(['logo_path' => 'settings/logo.png']);
    Once::flush(); // a query-builder update fires no saved event
    $admin = User::factory()->create()->assignRole(RoleName::Admin);
    expect($admin->requiresTwoFactor())->toBeTrue();

    $response = $this->actingAs($admin)->get(route('brand.logo'))->assertOk();

    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toBe('image/png');
});

test('Admin sees every Administration and Ledger item', function () {
    CompanySetting::factory()->create();
    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);

    $page = $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        ->assertSee('data-nav-group="administration"', false)
        ->assertSee('data-nav-group="ledger"', false);
    foreach (['Users', 'Roles', 'Company settings', 'Facilities', 'Unit types', 'Banks', 'Custom fields', 'Tenant reminders', 'Email settings',
        'Contract templates', 'Audit log', 'Ledger health', 'Trial balance', 'Balance sheet', 'Profit and loss', 'General ledger',
        'Journal list', 'Cash and bank movements', 'VAT returns', 'Bank reconciliation', 'Accounts and rules', 'Start date and opening balances'] as $label) {
        $page->assertSee($label);
    }
});

test('a Property manager sees no Finance, Ledger or Administration items', function () {
    CompanySetting::factory()->create();
    $manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);

    $this->actingAs($manager)->get(route('dashboard'))->assertOk()
        ->assertSee('data-nav-group="property"', false)
        ->assertDontSee('data-nav-group="finance"', false)
        ->assertDontSee('data-nav-group="ledger"', false)
        ->assertDontSee('data-nav-group="administration"', false);
});

test('Management sees Approvals with the pending count', function () {
    CompanySetting::factory()->create();
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $contract = OwnerContract::factory()->create(['created_by' => $finance->id]);
    $contract->units()->attach(Unit::factory()->create(['building_id' => $contract->building_id])->id);
    app(SubmitOwnerContract::class)->handle($finance, $contract);

    $this->actingAs($management)->get(route('dashboard'))->assertOk()
        ->assertSeeInOrder([route('approvals.index'), 'Approvals', '>1<'], false);
});

test('the staff sign-in page is a card with the company brand panel', function () {
    CompanySetting::factory()->create(['name_en' => 'Gulf Homes W.L.L.']);

    $this->get(route('login'))->assertOk()
        ->assertSee('data-ui-auth-card', false)
        ->assertSeeInOrder(['Gulf Homes W.L.L.', 'Log in to your account']);
});

test('the portal sign-in page is the same card, in Arabic too', function () {
    CompanySetting::factory()->create(['name_en' => 'Gulf Homes', 'name_ar' => 'بيوت الخليج']);

    $this->get(route('portal.login'))->assertOk()->assertSee('data-ui-auth-card', false)->assertSee('Sign in to the portal');
});
