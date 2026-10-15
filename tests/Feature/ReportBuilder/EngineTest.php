<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    $this->tenantA = Customer::factory()->create(['name_en' => 'Alpha Tenant']);
    $this->tenantB = Customer::factory()->create(['name_en' => 'Beta Tenant']);
    $agA = activeAgreement(['customer_id' => $this->tenantA->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [$ua = Unit::factory()->for($this->a)->create()]);
    $agB = activeAgreement(['customer_id' => $this->tenantB->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [$ub = Unit::factory()->for($this->b)->create()]);
    $this->inv1 = issuedInvoice($this->tenantA, [['net' => '400.000', 'au' => $agA->agreementUnits->first()]], '2026-10-12', $agA);
    $this->inv2 = issuedInvoice($this->tenantA, [['net' => '150.500', 'au' => $agA->agreementUnits->first()]], '2026-09-01', $agA);
    $this->inv3 = issuedInvoice($this->tenantB, [['net' => '300.000', 'au' => $agB->agreementUnits->first()]], '2026-10-14', $agB);
});

test('detail rows carry the chosen columns, sorted, with a grand total and a row count', function () {
    $r = runReport($this->finance, ['subject' => 'invoices', 'columns' => ['number', 'tenant', 'building', 'balance'],
        'totals' => ['balance' => 'sum'], 'sort' => ['field' => 'balance', 'dir' => 'desc']]);

    expect(array_column($r->rows, 'balance'))->toBe(['400.000', '300.000', '150.500'])
        ->and(array_column($r->rows, 'building'))->toBe(['AAA', 'BBB', 'AAA'])
        ->and($r->rowCount)->toBe(3)->and($r->truncated)->toBeFalse()
        ->and($r->grandTotals)->toMatchArray(['count' => 3, 'balance:sum' => '850.500']);
});

test('filters, the period and the building narrow the rows', function () {
    $base = ['subject' => 'invoices', 'columns' => ['number', 'balance']];

    expect(runReport($this->finance, [...$base, 'filters' => [['field' => 'balance', 'op' => 'gt', 'value' => '200']]])->rowCount)->toBe(2)
        ->and(runReport($this->finance, [...$base, 'filters' => [['field' => 'status', 'op' => 'in', 'value' => ['overdue']]]])->rowCount)->toBe(1) // 1 Sep + 5 days grace is past; the October ones are still in grace
        ->and(runReport($this->finance, [...$base, 'filters' => [['field' => 'tenant', 'op' => 'contains', 'value' => 'Beta']]])->rowCount)->toBe(1)
        ->and(runReport($this->finance, [...$base, 'period' => ['field' => 'due_date', 'preset' => 'this_month']])->rowCount)->toBe(2)
        ->and(runReport($this->finance, [...$base, 'building_id' => $this->b->id])->rowCount)->toBe(1);
});

test('the on-screen limit truncates detail rows but not the count or totals', function () {
    $r = app(Engine::class)->run($this->finance, ReportDefinition::fromArray(['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'totals' => ['balance' => 'sum']], $this->finance), limit: 2);

    expect($r->rows)->toHaveCount(2)->and($r->truncated)->toBeTrue()->and($r->rowCount)->toBe(3)->and($r->grandTotals['balance:sum'])->toBe('850.500');
});

test('a user limited to one building never sees another building, whatever the filters', function () {
    $leasing = matrixUser(RoleName::Leasing, $this->a);
    $r = runReport($leasing, ['subject' => 'agreements', 'columns' => ['number', 'tenant', 'building']]);

    expect(array_column($r->rows, 'building'))->toBe(['AAA']);
});
