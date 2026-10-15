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
use App\Reports\Builder\Engine;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
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

test('changing a filter field replaces its operator and value with the new field defaults', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->call('addFilter')->set('def.filters.0.field', 'balance')->set('def.filters.0.op', 'gt')->set('def.filters.0.value', '500')
        ->set('def.filters.0.field', 'tenant')
        ->assertSet('def.filters.0.op', 'contains')->assertSet('def.filters.0.value', null);
});

test('choosing a filter field picks a sensible default comparison for its type', function () {
    $t = Livewire::actingAs($this->finance)->test(Builder::class)->call('addFilter');
    $t->set('def.filters.0.field', 'number')->assertSet('def.filters.0.op', 'contains')
        ->set('def.filters.0.field', 'balance')->assertSet('def.filters.0.op', 'gte')
        ->set('def.filters.0.field', 'status')->assertSet('def.filters.0.op', 'in')->assertSet('def.filters.0.value', [])
        ->set('def.filters.0.field', 'due_date')->assertSet('def.filters.0.op', 'between')->assertSet('def.filters.0.value', [null, null])
        ->set('def.filters.0.field', '')->assertSet('def.filters.0.op', '');
});

test('filter rows say what value they want', function () {
    $t = Livewire::actingAs($this->finance)->test(Builder::class)->call('addFilter');
    $t->set('def.filters.0.field', 'number')->assertSeeHtml('Type part of the Invoice')->assertSee('Invoice contains')
        ->set('def.filters.0.op', 'is')->assertSeeHtml('Exact Invoice')
        ->set('def.filters.0.field', 'balance')->assertSeeHtml('Amount in BHD')->assertSee('Balance ≥');
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

test('boolean fields default to yes and their help text has no trailing value marker', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->call('setSubject', 'deposits_held')->call('addFilter')
        ->set('def.filters.0.field', 'settled')->assertSet('def.filters.0.op', 'yes')->assertSee('Settled is yes')->tap(fn ($t) => expect($t->html())->not->toMatch('/is (yes|empty)(<!--.*?-->)*[[:space:]]*…/s'));
});

test('no-value operators show help without an ellipsis', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->call('addFilter')
        ->set('def.filters.0.field', 'number')->set('def.filters.0.op', 'empty')
        ->assertSee('Invoice is empty')->tap(fn ($t) => expect($t->html())->not->toMatch('/is (yes|empty)(<!--.*?-->)*[[:space:]]*…/s'));
});

test('number and money between filters show their placeholders', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)->call('addFilter')
        ->set('def.filters.0.field', 'days_overdue')->set('def.filters.0.op', 'eq')->assertSeeHtml('Number, e.g. 30')
        ->set('def.filters.0.field', 'balance')->set('def.filters.0.op', 'between')
        ->assertSeeHtml('placeholder="From"')->assertSeeHtml('placeholder="To"');
});

test('grouping shows a subtotal row with its total, and Summary only hides detail rows', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('def.groups', [['field' => 'building', 'bucket' => null]])->set('def.totals', ['balance' => 'sum'])
        ->assertSeeHtml('data-group-level="1"')->assertSeeHtmlInOrder(['data-group-level="1"', 'AAA', '(1)', '400.000', 'Alpha Tenant', 'Total (1)'])
        ->assertSeeHtml('aria-expanded')->assertSee('Collapse AAA')
        ->set('def.summary_only', true)->assertSeeHtml('data-group-level="1"')->assertDontSee('Alpha Tenant');
});

test('two levels with a date bucket show level-2 rows labelled by month', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('def.groups', [['field' => 'building', 'bucket' => null], ['field' => 'due_date', 'bucket' => null]])->set('def.totals', ['balance' => 'sum'])
        ->assertSeeHtmlInOrder(['data-group-level="1"', 'AAA', 'data-group-level="2"', 'Oct 2026', '400.000', 'Alpha Tenant']);
});

test('an invoice spanning buildings shows under Multiple', function () {
    $other = Building::factory()->create(['code' => 'BBB']);
    $this->finance->buildings()->attach($other->id);
    $tenant = Customer::factory()->create(['name_en' => 'Gamma Tenant']);
    $ag = activeAgreement(['customer_id' => $tenant->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'],
        [Unit::factory()->for($this->building)->create(), Unit::factory()->for($other)->create()]);
    issuedInvoice($tenant, [['net' => '10.000', 'au' => $ag->agreementUnits[0]], ['net' => '20.000', 'au' => $ag->agreementUnits[1]]], '2026-10-14', $ag);

    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('def.groups', [['field' => 'building', 'bucket' => null]])->set('def.totals', ['balance' => 'sum'])
        ->assertSeeHtmlInOrder(['data-group-level="1"', 'Multiple', '(1)', '30.000', 'Gamma Tenant']);
});

test('the groups-truncated note shows when the engine says so', function () {
    app()->instance(Engine::class, new class extends Engine
    {
        public function run(User $user, ReportDefinition $def, ?int $limit = self::DETAIL_LIMIT): ReportResult
        {
            $r = parent::run($user, $def, $limit);

            return new ReportResult($r->columns, $r->rows, $r->rowCount, $r->truncated, $r->grandTotals, $r->groups, true, $r->periodLabel);
        }
    });
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('def.groups', [['field' => 'building', 'bucket' => null]])->assertSee('Showing the first 1,000 groups — export for all');
});

test('tampered group fields and buckets never crash the page', function () {
    foreach ([['field' => ['x']], ['field' => 'building', 'bucket' => ['x']], ['field' => 'due_date', 'bucket' => ['x']], 'x'] as $group) {
        Livewire::actingAs($this->finance)->test(Builder::class)->set('def.groups.0', $group)->assertOk();
    }
    Livewire::actingAs($this->finance)->test(Builder::class)->set('def.groups.0.field', ['x'])->assertOk();
    Livewire::actingAs($this->finance)->test(Builder::class)->set('def.totals', ['balance' => ['x']])->assertOk();
});

test('empty picker rows are dropped and a date group defaults to month', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->set('def.groups', [['field' => '']])->set('def.totals', ['balance' => ''])
        ->assertDontSee('Choose a field to group by.')->assertSee('Alpha Tenant')
        ->set('def.groups', [['field' => 'due_date']])->assertDontSee('Group dates by')->assertSee('Oct 2026');
});
