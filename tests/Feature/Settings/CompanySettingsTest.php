<?php

use App\Enums\RoleName;
use App\Enums\TaxCategory;
use App\Livewire\Admin\CompanySettings;
use App\Models\CompanySetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
});

test('guests are redirected and users without settings.manage are forbidden', function () {
    $this->get(route('admin.settings'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))
        ->get(route('admin.settings'))->assertForbidden();
});

test('an admin sees and saves the settings', function () {
    $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()->assertSee('Company settings');

    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.name_en', 'Example Properties W.L.L.')
        ->set('form.name_ar', 'شركة مثال للعقارات')
        ->set('form.vat_registered', true)
        ->set('form.trn', '200000000000003')
        ->set('form.vat_rate', '10.00')
        ->set('form.commercial_tax_category', 'standard')
        ->set('form.default_grace_days', 7)
        ->call('save')
        ->assertHasNoErrors();

    $settings = CompanySetting::current();
    expect($settings->name_en)->toBe('Example Properties W.L.L.')
        ->and($settings->vat_registered)->toBeTrue()
        ->and($settings->commercial_tax_category)->toBe(TaxCategory::Standard)
        ->and($settings->default_grace_days)->toBe(7);
});

test('invalid values are rejected', function () {
    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.name_en', '')
        ->set('form.vat_registered', true)
        ->set('form.trn', '')
        ->set('form.vat_rate', '10.555')
        ->set('form.currency_code', 'bh')
        ->set('form.date_format', 'm/d/Y')
        ->set('form.default_grace_days', 61)
        ->set('form.residential_tax_category', 'nonsense')
        ->call('save')
        ->assertHasErrors(['form.name_en', 'form.trn', 'form.vat_rate', 'form.currency_code', 'form.date_format', 'form.default_grace_days', 'form.residential_tax_category']);
});

test('the logo is stored privately', function () {
    Storage::fake('local');

    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('logo', UploadedFile::fake()->image('logo.png', 200, 80))
        ->call('save')
        ->assertHasNoErrors();

    expect(CompanySetting::current()->logo_path)->toBe('settings/logo.png');
    Storage::disk('local')->assertExists('settings/logo.png');
});

test('the screen cannot change install-level settings', function () {
    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.require_different_approver', false)
        ->set('form.go_live_at', '2026-01-01')
        ->call('save');

    $settings = CompanySetting::current();
    expect($settings->require_different_approver)->toBeTrue()->and($settings->go_live_at)->toBeNull();
});

test('only one settings row can exist', function () {
    expect(fn () => DB::table('company_settings')->insert(['id' => 2, 'name_en' => 'Second']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});

test('changes are audited', function () {
    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.name_en', 'Renamed Co')
        ->call('save');

    $row = Activity::query()->where('subject_type', (new CompanySetting)->getMorphClass())->where('event', 'updated')->latest('id')->firstOrFail();
    expect($row->causer_id)->toBe($this->admin->id)
        ->and($row->attribute_changes['attributes']['name_en'])->toBe('Renamed Co');
});

test('contracts can leave blank space for stamp paper and carry a company letterhead', function () {
    Storage::fake('local');

    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.contract_stamp_space_mm', 55)
        ->set('contractHeader', UploadedFile::fake()->image('letterhead.png', 1200, 200))
        ->call('save')
        ->assertHasNoErrors();

    $settings = CompanySetting::current();
    expect($settings->contract_stamp_space_mm)->toBe(55)->and($settings->contract_header_path)->toBe('settings/contract-header.png');
    Storage::disk('local')->assertExists('settings/contract-header.png');

    Livewire::actingAs($this->admin)->test(CompanySettings::class)->set('removeContractHeader', true)->call('save')->assertHasNoErrors();
    expect(CompanySetting::current()->contract_header_path)->toBeNull();
    Storage::disk('local')->assertMissing('settings/contract-header.png');
});

test('the stamp space is limited to 0–120 mm', function () {
    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.contract_stamp_space_mm', 200)->call('save')->assertHasErrors('form.contract_stamp_space_mm');
});
