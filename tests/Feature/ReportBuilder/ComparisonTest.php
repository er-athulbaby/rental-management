<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Reports\Builder\Comparison;
use App\Reports\Builder\ExcelWriter;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    $this->finance->buildings()->attach($this->b->id);
    $this->tenantA = Customer::factory()->create(['name_en' => 'Alpha Tenant']);
    $this->tenantB = Customer::factory()->create(['name_en' => 'Beta Tenant']);
    $agA = activeAgreement(['customer_id' => $this->tenantA->id, 'start_date' => '2025-01-01', 'end_date' => '2027-08-31'], [Unit::factory()->for($this->a)->create()]);
    $agB = activeAgreement(['customer_id' => $this->tenantB->id, 'start_date' => '2025-01-01', 'end_date' => '2027-08-31'], [Unit::factory()->for($this->b)->create()]);
    issuedInvoice($this->tenantA, [['net' => '900.000', 'au' => $agA->agreementUnits->first()]], '2026-08-01', $agA);
    issuedInvoice($this->tenantB, [['net' => '100.000', 'au' => $agB->agreementUnits->first()]], '2025-09-01', $agB);
    $pay = fn ($c, $on, $amt) => app(RecordPayment::class)->handle($this->finance, $c, ['received_on' => $on, 'method' => 'cash', 'amount' => $amt]);
    $pay($this->tenantA, '2026-10-05', '300.000');
    $pay($this->tenantA, '2026-09-10', '100.000');
    $pay($this->tenantB, '2025-10-07', '50.000');
    $this->base = ['subject' => 'payments', 'columns' => ['number', 'amount'], 'totals' => ['amount' => 'sum'],
        'period' => ['field' => 'received_on', 'preset' => 'this_month'], 'groups' => [['field' => 'tenant']]];
});

test('previous period: this month against last month, per tenant, with change and change %', function () {
    $r = runReport($this->finance, [...$this->base, 'compare' => 'previous']);

    expect($r->comparison['label'])->toBe('01/10/2026 – 31/10/2026')->and($r->comparison['compareLabel'])->toBe('01/09/2026 – 30/09/2026');
    $row = collect($r->comparison['rows'])->firstWhere('label', 'Alpha Tenant');
    expect($row['current']['amount:sum'])->toBe('300.000')->and($row['previous']['amount:sum'])->toBe('100.000')
        ->and(Comparison::change('300.000', '100.000'))->toBe(['diff' => '200.000', 'pct' => '200.0']);
});

test('a group in only one period shows 0 for the other; change % is blank against 0', function () {
    $r = runReport($this->finance, [...$this->base, 'compare' => 'last_year']);
    $beta = collect($r->comparison['rows'])->firstWhere('label', 'Beta Tenant');

    expect($beta['current']['amount:sum'])->toBe('0.000')->and($beta['previous']['amount:sum'])->toBe('50.000')->and($beta['current']['count'])->toBe(0)
        ->and(Comparison::change('10.000', '0.000')['pct'])->toBeNull();
});

test('an ungrouped report compares its grand total', function () {
    $r = runReport($this->finance, [...$this->base, 'groups' => [], 'compare' => 'previous']);

    expect($r->comparison['rows'])->toHaveCount(1)->and($r->comparison['rows'][0]['current']['amount:sum'])->toBe('300.000')
        ->and($r->comparison['rows'][0]['previous']['count'])->toBe(1);
});

test('no comparison unless asked for', function () {
    expect(runReport($this->finance, $this->base)->comparison)->toBeNull();
});

test('change is exact decimal arithmetic, rounds half up, and treats nulls as 0', function () {
    expect(Comparison::change('0.300', '0.100')['diff'])->toBe('0.200')
        ->and(Comparison::change('1.000', '3.000'))->toBe(['diff' => '-2.000', 'pct' => '-66.7'])
        ->and(Comparison::change('1.005', '1.000')['pct'])->toBe('0.5')
        ->and(Comparison::change('1.050', '1.000')['pct'])->toBe('5.0')
        ->and(Comparison::change('0.995', '1.000')['pct'])->toBe('-0.5')
        ->and(Comparison::change(4, 3))->toBe(['diff' => '1.000', 'pct' => '33.3'])
        ->and(Comparison::change(null, '5.000'))->toBe(['diff' => '-5.000', 'pct' => '-100.0']);
});

test('the comparison honours filters and the building filter', function () {
    $r = runReport($this->finance, [...$this->base, 'compare' => 'last_year', 'building_id' => $this->b->id]);
    expect(collect($r->comparison['rows'])->pluck('label')->all())->toBe(['Beta Tenant']);

    $f = runReport($this->finance, [...$this->base, 'compare' => 'previous', 'filters' => [['field' => 'amount', 'op' => 'gt', 'value' => '150']]]);
    $alpha = collect($f->comparison['rows'])->firstWhere('label', 'Alpha Tenant');
    expect($alpha['previous']['amount:sum'])->toBe('0.000')->and($alpha['current']['amount:sum'])->toBe('300.000');
});

test('the screen shows both period dates and the change with an arrow, and the chart pairs the periods with a legend', function () {
    Livewire::actingAs($this->finance)->test(Builder::class)
        ->call('setSubject', 'payments')
        ->set('def.period', ['field' => 'received_on', 'preset' => 'this_month', 'from' => null, 'to' => null])
        ->set('def.groups', [['field' => 'tenant', 'bucket' => null]])->set('def.totals', ['amount' => 'sum'])->set('def.compare', 'previous')
        ->assertSee('01/09/2026 – 30/09/2026')->assertSee('▲')->assertSee('(200.0%)')->assertDontSeeHtml('<figure class="viz')
        ->set('def.chart', ['type' => 'bars', 'total' => 'amount:sum'])
        ->assertSeeHtml('<figure class="viz')->assertSee('Alpha Tenant (previous)')->assertSeeHtml('data-legend')
        ->set('def.compare', '')->assertDontSee('01/09/2026')->assertDontSeeHtml('data-legend');
});

test('a tampered compare value never crashes the page', function () {
    foreach ([['x'], 'sideways', 5] as $value) {
        Livewire::actingAs($this->finance)->test(Builder::class)->set('def.compare', $value)->assertOk();
    }
});

test('Excel carries a comparison block under the grand total', function () {
    $path = tempnam(sys_get_temp_dir(), 'cmp').'.xlsx';
    ExcelWriter::write(runReport($this->finance, [...$this->base, 'compare' => 'previous']), $path);
    $rows = SimpleExcelReader::create($path)->noHeaderRow()->getRows()->map(fn ($r) => array_values($r))->all();
    @unlink($path);

    $at = collect($rows)->search(fn ($r) => ($r[0] ?? null) === 'Comparison');
    expect($at)->not->toBeFalse()
        ->and($rows[$at + 1])->toContain('Compared')->and(implode('|', $rows[$at + 1]))->toContain('Rows: 01/10/2026 – 31/10/2026')->toContain('Rows: 01/09/2026 – 30/09/2026')
        ->and(collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Alpha Tenant' && in_array(200, $r)))->not->toBeNull();
});
