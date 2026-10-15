<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\SavedReport;
use App\Models\Unit;
use App\Models\User;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
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

test('a saved report opens with its definition and name, and only users who may view it', function () {
    $def = ReportDefinition::fresh(Registry::get('invoices'), $this->finance)->toArray();
    $report = SavedReport::create(['name' => 'My invoices', 'subject' => 'invoices', 'definition' => $def, 'visibility' => 'private',
        'created_by' => $this->finance->id, 'updated_by' => $this->finance->id]);

    Livewire::actingAs($this->finance)->test(Builder::class, ['report' => $report])
        ->assertSet('name', 'My invoices')->assertSet('reportId', $report->id)->assertSet('def.subject', 'invoices');

    $other = matrixUser(RoleName::Finance, $this->building);
    Livewire::actingAs($other)->test(Builder::class, ['report' => $report])->assertForbidden();
});

test('setSubject refuses subjects the user may not use and unknown ones', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->building);
    expect(isset(Registry::forUser($leasing)['invoices']))->toBeFalse();

    Livewire::actingAs($leasing)->test(Builder::class)->call('setSubject', 'invoices')->assertForbidden();
    Livewire::actingAs($this->finance)->test(Builder::class)->call('setSubject', 'nope')->assertForbidden();
});

test('changing a filter field resets its operator and value', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->call('addFilter')->set('def.filters.0.field', 'balance')->set('def.filters.0.op', 'gt')->set('def.filters.0.value', '500')
        ->set('def.filters.0.field', 'tenant')
        ->assertSet('def.filters.0.op', '')->assertSet('def.filters.0.value', null);
});

test('columns can be moved and filters removed', function () {
    $t = Livewire::actingAs($this->finance)->test(Builder::class);
    $cols = $t->get('def.columns');
    $t->call('moveColumn', $cols[0], 1)->assertSet('def.columns', [$cols[1], $cols[0], ...array_slice($cols, 2)])
        ->call('moveColumn', $cols[0], 99)->assertSet('def.columns', [$cols[1], $cols[0], ...array_slice($cols, 2)]);

    $t->call('addFilter')->call('addFilter')->set('def.filters.1.field', 'tenant')
        ->call('removeFilter', 0)->assertSet('def.filters', fn (array $f) => count($f) === 1 && $f[0]['field'] === 'tenant');
});

test('a tampered subject, as-at or filters show the callout, not a server error', function () {
    foreach ([['subject', 'nope'], ['subject', ['x']], ['as_at', 'garbage'], ['as_at', ['x']], ['filters', 'x'], ['filters', [5, ['field' => ['a']]]], ['columns', 'x']] as [$key, $value]) {
        Livewire::actingAs($this->finance)->test(Builder::class)->set("def.$key", $value)->assertOk();
    }
    Livewire::actingAs($this->finance)->test(Builder::class)->set('def.columns', 'x')->assertSee('Finish the settings');
    Livewire::actingAs($this->finance)->test(Builder::class)->call('removeFilter', 0)->assertOk();
});

test('ticking two enum options makes an in filter that narrows the results', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->call('addFilter')->set('def.filters.0.field', 'status')
        ->assertSet('def.filters.0.value', [])
        ->set('def.filters.0.op', 'in')->set('def.filters.0.value', ['paid', 'cancelled'])
        ->assertDontSee('Alpha Tenant')
        ->set('def.filters.0.value', ['paid', 'overdue'])->assertSee('Alpha Tenant');
});
