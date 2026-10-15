<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use App\Reports\Builder\ReportResult;
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

if (! function_exists('runReport')) {
    function runReport(User $user, array $data): ReportResult
    {
        return app(Engine::class)->run($user, ReportDefinition::fromArray($data, $user));
    }
}

test('one level: subtotals per building, sorted by key, with the grand total unchanged', function () {
    $r = runReport($this->finance, ['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'groups' => [['field' => 'building']], 'totals' => ['balance' => 'sum']]);

    expect(array_map(fn ($g) => [$g['label'], $g['totals']['count'], $g['totals']['balance:sum']], $r->groups))
        ->toBe([['AAA', 2, '550.500'], ['BBB', 1, '300.000']])
        ->and($r->grandTotals['balance:sum'])->toBe('850.500');
});

test('two levels: building then month of due date, level-2 rows under each subtotal', function () {
    $r = runReport($this->finance, ['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'groups' => [['field' => 'building'], ['field' => 'due_date', 'bucket' => 'month']], 'totals' => ['balance' => 'sum'], 'summary_only' => true]);

    expect(array_map(fn ($g) => [$g['level'], $g['label'], $g['totals']['balance:sum']], $r->groups))->toBe([
        [1, 'AAA', '550.500'], [2, 'Sep 2026', '150.500'], [2, 'Oct 2026', '400.000'],
        [1, 'BBB', '300.000'], [2, 'Oct 2026', '300.000'],
    ])->and($r->rows)->toBe([]);
});

test('average per group', function () {
    $r = runReport($this->finance, ['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'totals' => ['balance' => 'avg'], 'groups' => [['field' => 'tenant']]]);
    expect(collect($r->groups)->firstWhere('label', 'Alpha Tenant')['totals']['balance:avg'])->toBe('275.250');
});

test('an invoice spanning buildings lands under "Multiple" once, with no double counting', function () {
    $ub2 = Unit::factory()->for($this->b)->create();
    $agM = activeAgreement(['customer_id' => $this->tenantB->id, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'], [$ub2, Unit::factory()->for($this->a)->create()]);
    issuedInvoice($this->tenantB, [
        ['net' => '10.000', 'au' => $agM->agreementUnits[0]],
        ['net' => '20.000', 'au' => $agM->agreementUnits[1]],
    ], '2026-10-14', $agM);

    $r = runReport($this->finance, ['subject' => 'invoices', 'columns' => ['number', 'balance'],
        'groups' => [['field' => 'building']], 'totals' => ['balance' => 'sum']]);

    $multiple = collect($r->groups)->firstWhere('label', 'Multiple');
    expect($multiple['totals']['count'])->toBe(1)->and($multiple['totals']['balance:sum'])->toBe('30.000')
        ->and(collect($r->groups)->sum('totals.count'))->toBe($r->rowCount);
});
