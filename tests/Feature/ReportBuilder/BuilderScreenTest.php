<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureNumberSequences::class)(2026);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create(['code' => 'AAA']);
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create(['name_en' => 'Alpha Tenant'])->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'],
        [Unit::factory()->for($this->building)->create()]);
    issuedInvoice($agreement->customer, [['net' => '400.000', 'au' => $agreement->agreementUnits->first()]], '2026-10-01', $agreement);
});

test('a new report starts on invoices with its default columns and shows live results', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->assertSet('def.subject', 'invoices')
        ->assertSee('Alpha Tenant')->assertSee('400.000')
        ->call('toggleColumn', 'vat')->assertSet('def.columns', fn (array $c) => in_array('vat', $c, true))
        ->call('setSubject', 'agreements')->assertSet('def.columns', ['number', 'tenant', 'building', 'start_date', 'end_date', 'monthly_rent', 'status']);
});

test('filters change the results and bad filters show an error instead of results', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->call('addFilter')->set('def.filters.0.field', 'balance')->set('def.filters.0.op', 'gt')->set('def.filters.0.value', '500')
        ->assertDontSee('Alpha Tenant')
        ->set('def.filters.0.value', 'abc')->assertSee('Enter a number.');
});

test('only users with report access may open the builder', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())->get(route('reports.builder'))->assertForbidden();
});

test('choosing all buildings (empty select value) is not an error', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('def.building_id', $this->building->id)->assertSee('Alpha Tenant')
        ->set('def.building_id', '')->assertSee('Alpha Tenant')->assertDontSee('Choose a building.');
});
