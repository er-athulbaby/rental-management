<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\Reports\IncomeStatementReport;
use App\Livewire\Reports\OwnerIncomeStatementReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use App\Reports\BuildingProfitability;
use App\Reports\IncomeStatement;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** A statement as key => [amount per month], so a whole statement is checked to the fil, in order. */
function statementGrid(array $statement): array
{
    return array_map(fn (array $r) => array_values($r['amounts']), $statement['rows']);
}

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    $this->other = Building::factory()->create();
    [$owned, $leasedUnit, $managedUnit] = Unit::factory()->for($this->building)->count(3)->create()->all();
    $this->leased = activeOwnerContract(['owner_id' => Owner::factory()->create(['name_en' => 'Leila Lessor'])->id, 'building_id' => $this->building->id, 'type' => 'leased',
        'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-03-01', 'end_date' => '2027-02-28'], [$leasedUnit]);
    $this->managed = activeOwnerContract(['owner_id' => Owner::factory()->create(['name_en' => 'Mansour Manager'])->id, 'building_id' => $this->building->id, 'type' => 'managed',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [$managedUnit]);
    // A draft leased contract in the other building: its owner is listed only to users who can see that building.
    OwnerContract::factory()->leased()->create(['building_id' => $this->other->id, 'owner_id' => Owner::factory()->create(['name_en' => 'Omar Outside'])->id]);

    // The other building has its own leased unit, so building scope is tested both ways: 80 rent (paid) and a 25 head lease (paid).
    $otherUnit = Unit::factory()->for($this->other)->create();
    $otherLeased = activeOwnerContract(['owner_id' => Owner::factory()->create(['name_en' => 'Olga Otherside'])->id, 'building_id' => $this->other->id, 'type' => 'leased',
        'rent_amount' => '25.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-03-01', 'end_date' => '2027-02-28'], [$otherUnit]);

    $payable = fn (string $due, string $amount, ?OwnerContract $contract = null) => tap((new OwnerPayable)->forceFill(['owner_contract_id' => ($contract ?? $this->leased)->id, 'period_start' => $due,
        'period_end' => CarbonImmutable::parse($due)->endOfMonth()->toDateString(), 'due_date' => $due, 'amount' => $amount, 'status' => 'scheduled']))->save();
    $march = $payable('2026-03-01', '50.000');
    app(RecordDisbursement::class)->handle($this->finance, ['purpose' => 'head_lease', 'owner_payable_id' => $march->id, 'amount' => '50.000', 'method' => 'bank_transfer', 'paid_on' => '2026-03-05']);
    $otherMarch = $payable('2026-03-01', '25.000', $otherLeased);
    app(RecordDisbursement::class)->handle($this->finance, ['purpose' => 'head_lease', 'owner_payable_id' => $otherMarch->id, 'amount' => '25.000', 'method' => 'bank_transfer', 'paid_on' => '2026-03-05']);

    // Issued 5 March. Owned unit: rent 100 + VAT, service 20 + VAT, damage 15, deposit 200. Leased unit: rent 100 + VAT,
    // parking 10 + VAT. Managed unit: rent 100 + VAT (the owner's money), deposit 100. Plus a manual line with no unit.
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$owned, $leasedUnit, $managedUnit]);
    $this->au = $this->agreement->agreementUnits()->orderBy('id')->get();
    issuedInvoice($this->customer, [
        ['net' => '100.000', 'tax' => 'standard', 'au' => $this->au[0]], ['type' => 'service_charge', 'net' => '20.000', 'tax' => 'standard', 'au' => $this->au[0]],
        ['type' => 'damage', 'net' => '15.000', 'au' => $this->au[0]], ['type' => 'deposit', 'net' => '200.000', 'au' => $this->au[0]],
        ['net' => '100.000', 'tax' => 'standard', 'au' => $this->au[1]], ['type' => 'parking', 'net' => '10.000', 'tax' => 'standard', 'au' => $this->au[1]],
        ['net' => '100.000', 'tax' => 'standard', 'au' => $this->au[2]], ['type' => 'deposit', 'net' => '100.000', 'au' => $this->au[2]],
    ], '2026-03-01', $this->agreement); // 645 net + 33 VAT = 678
    issuedInvoice($this->customer, [['type' => 'other', 'net' => '40.000', 'description' => 'Key replacement']], '2026-03-01');

    $otherCustomer = Customer::factory()->create();
    $otherAgreement = activeAgreement(['customer_id' => $otherCustomer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$otherUnit]);
    issuedInvoice($otherCustomer, [['net' => '80.000', 'au' => $otherAgreement->agreementUnits()->sole()]], '2026-03-01', $otherAgreement); // no VAT

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '718.000']); // pays both in full
    app(RecordPayment::class)->handle($this->finance, $otherCustomer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '80.000']);
    $this->expense = fn (array $e) => app(RecordExpense::class)->handle($this->finance, ['description' => 'Fixture', 'expense_date' => now()->toDateString(), ...$e]);
    ($this->expense)(['building_id' => $this->building->id, 'category' => 'cleaning', 'net' => '30.000', 'tax_amount' => '3.000', 'charge_to' => 'company']);
    ($this->expense)(['building_id' => $this->building->id, 'unit_id' => $managedUnit->id, 'category' => 'maintenance', 'net' => '20.000', 'charge_to' => 'owner']);
    ($this->expense)(['building_id' => $this->other->id, 'category' => 'legal', 'net' => '7.000', 'charge_to' => 'company']);

    // The managed contract's March statement, finalised: fee 10.000 + 1.000 VAT, closing 100 − 20 − 11 = 69.000.
    $this->travelTo(CarbonImmutable::parse('2026-04-01 04:00', 'Asia/Bahrain'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, OwnerStatement::sole()), true);
    $this->march = OwnerStatement::sole();

    // April: rent 100 + VAT on the owned unit, half credited (55 gross = 50 net), unpaid; a security expense; the April
    // head-lease payable of 50 rescheduled to 30 (the old one cancelled and replaced).
    $this->travelTo(CarbonImmutable::parse('2026-04-02 09:00', 'Asia/Bahrain'));
    $april = issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard', 'au' => $this->au[0]]], '2026-04-01', $this->agreement);
    $cn = app(SaveCreditNote::class)->handle($this->finance, $april, null, ['reason' => 'Goodwill', 'lines' => [['credited_line_id' => $april->lines->sole()->id, 'amount' => '55.000']]]);
    app(DecideApproval::class)->handle($this->management, app(SubmitCreditNote::class)->handle($this->finance, $cn), true);
    ($this->expense)(['building_id' => $this->building->id, 'category' => 'security', 'net' => '12.000', 'charge_to' => 'company']);
    $old = $payable('2026-04-01', '50.000');
    $new = $payable('2026-04-01', '30.000');
    $old->forceFill(['status' => 'cancelled'])->save();
    $old->forceFill(['replaced_by_payable_id' => $new->id])->save();
});

test('collected, all buildings: company-earned lines by month, net of VAT, without managed rent or deposits', function () {
    expect(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED)))->toBe([
        'rent' => [280_000, 0],             // owned + leased + the other building (80); the managed unit's 100 is the owner's
        'service_charge' => [20_000, 0],
        'parking' => [10_000, 0],
        'other_charges' => [15_000, 0],     // damage
        'unlinked' => [40_000, 0],          // the manual line with no unit
        'management_fees' => [10_000, 0],   // posted at the March cutoff
        'total_income' => [375_000, 0],
        'head_lease' => [75_000, 0],        // 50 + the other building's 25, paid 5 March
        'expense_cleaning' => [30_000, 0],  // net: the company is VAT-registered
        'expense_security' => [0, 12_000],
        'expense_legal' => [7_000, 0],      // the other building
        'total_costs' => [112_000, 12_000],
        'result' => [263_000, -12_000],
    ]);
});

test('billed, all buildings: issued lines less credit notes; head lease due, replaced payables left out', function () {
    expect(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::BILLED)))->toBe([
        'rent' => [280_000, 50_000],        // April: 100 issued − 50 credited
        'service_charge' => [20_000, 0],
        'parking' => [10_000, 0],
        'other_charges' => [15_000, 0],
        'unlinked' => [40_000, 0],
        'management_fees' => [10_000, 0],
        'total_income' => [375_000, 50_000],
        'head_lease' => [75_000, 30_000],   // the cancelled 50 is not counted
        'expense_cleaning' => [30_000, 0],
        'expense_security' => [0, 12_000],
        'expense_legal' => [7_000, 0],
        'total_costs' => [112_000, 42_000],
        'result' => [263_000, 8_000],
    ]);
});

test('one building: its lines only, never the lines without a unit; its Collected figures equal building profitability', function () {
    $statement = IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, [$this->building->id]);

    expect(statementGrid($statement))->toBe([
        'rent' => [200_000, 0],
        'service_charge' => [20_000, 0],
        'parking' => [10_000, 0],
        'other_charges' => [15_000, 0],
        'management_fees' => [10_000, 0],
        'total_income' => [255_000, 0],
        'head_lease' => [50_000, 0],
        'expense_cleaning' => [30_000, 0],
        'expense_security' => [0, 12_000],
        'total_costs' => [80_000, 12_000],
        'result' => [175_000, -12_000],
    ]);

    $profitability = BuildingProfitability::for($this->building, '2026-03-01', '2026-04-30');
    expect($profitability)->toBe([
        'collected' => 245_000, 'fees' => 10_000, 'income' => 255_000, 'billed' => 305_000,
        'head_lease' => 50_000, 'expenses' => 42_000, 'costs' => 92_000, 'result' => 163_000,
    ])->and($profitability['income'])->toBe($statement['rows']['total_income']['total'])
        ->and($profitability['result'])->toBe($statement['rows']['result']['total']);
});

test('the other building alone: its own rent and head lease, none of the first building\'s; owner and building together never widen', function () {
    expect(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, [$this->other->id])))->toBe([
        'rent' => [80_000, 0], 'total_income' => [80_000, 0],
        'head_lease' => [25_000, 0], 'expense_legal' => [7_000, 0], 'total_costs' => [32_000, 0], 'result' => [48_000, 0],
    ])->and(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::BILLED, [$this->other->id])))->toBe([
        'rent' => [80_000, 0], 'total_income' => [80_000, 0],
        'head_lease' => [25_000, 0], 'expense_legal' => [7_000, 0], 'total_costs' => [32_000, 0], 'result' => [48_000, 0],
    ]);

    // The leased owner of the first building, filtered to the other building: nothing.
    expect(IncomeStatement::isEmpty(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, [$this->other->id], $this->leased->owner_id)))->toBeTrue();
});

test('a company that is not VAT-registered counts the VAT on its expenses as a cost', function () {
    CompanySetting::current()->forceFill(['vat_registered' => false])->save();
    $grid = statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED));

    expect($grid['expense_cleaning'])->toBe([33_000, 0])
        ->and($grid['total_costs'])->toBe([115_000, 12_000])
        ->and($grid['result'])->toBe([260_000, -12_000]);
});

test('owner scope: a leased owner\'s units less their head lease; a managed owner\'s fees only', function () {
    expect(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, null, $this->leased->owner_id)))->toBe([
        'rent' => [100_000, 0], 'parking' => [10_000, 0], 'total_income' => [110_000, 0],
        'head_lease' => [50_000, 0], 'total_costs' => [50_000, 0], 'result' => [60_000, 0],
    ])->and(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::BILLED, null, $this->leased->owner_id)))->toBe([
        'rent' => [100_000, 0], 'parking' => [10_000, 0], 'total_income' => [110_000, 0],
        'head_lease' => [50_000, 30_000], 'total_costs' => [50_000, 30_000], 'result' => [60_000, -30_000],
    ])->and(statementGrid(IncomeStatement::company('2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, null, $this->managed->owner_id)))->toBe([
        'management_fees' => [10_000, 0], 'total_income' => [10_000, 0], 'total_costs' => [0, 0], 'result' => [10_000, 0],
    ]);
});

test('a period with nothing in it keeps only zero totals', function () {
    $statement = IncomeStatement::company('2025-06-01', '2025-06-30', IncomeStatement::COLLECTED);

    expect(statementGrid($statement))->toBe(['total_income' => [0], 'total_costs' => [0], 'result' => [0]])
        ->and(IncomeStatement::isEmpty($statement))->toBeTrue()
        ->and(IncomeStatement::months('2026-03-15', '2026-05-01'))->toBe(['2026-03', '2026-04', '2026-05']);
});

test('a managed owner: charges on their units less fee with VAT and their expenses; fee "–" until final; memo remittances net of a reversal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-03 10:00', 'Asia/Bahrain'));
    $remit = fn (string $on) => app(RecordDisbursement::class)->handle($this->finance, ['purpose' => 'owner_remittance', 'owner_statement_id' => $this->march->id,
        'amount' => '69.000', 'method' => 'bank_transfer', 'paid_on' => $on]);
    $first = $remit('2026-04-03');
    $this->travelTo(CarbonImmutable::parse('2026-04-05 10:00', 'Asia/Bahrain'));
    app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $first, 'Bounced by the bank'), true);
    $remit('2026-04-05');

    $collected = IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::COLLECTED);
    expect(statementGrid($collected))->toBe([
        'charge_rent' => [100_000, 0],   // net of VAT; the 100 deposit on their unit is left out
        'total_income' => [100_000, 0],
        'fee' => [11_000, null],         // 10 + 1 VAT; April's statement is not finalised
        'expenses' => [20_000, 0],
        'net' => [69_000, 0],
        'memo_paid' => [0, 69_000],      // 69 + 69 paid − 69 reversed
    ])->and($collected['rows']['fee']['label'])->toBe('Less management fee and VAT');

    expect(statementGrid(IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::BILLED))['charge_rent'])->toBe([100_000, 0]);
});

test('a managed owner\'s collected net income agrees with their finalised calendar-month statement', function () {
    $net = IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-03-31', IncomeStatement::COLLECTED)['rows']['net']['total'];

    expect($net)->toBe(Fils::fromDecimal($this->march->closing_balance) - Fils::fromDecimal($this->march->opening_balance))->and($net)->toBe(69_000);
});

test('a leased owner: the head lease earned, paid or due; memo head-lease payments', function () {
    expect(statementGrid(IncomeStatement::owner($this->leased->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::COLLECTED)))->toBe([
        'head_lease' => [50_000, 0], 'total_income' => [50_000, 0], 'net' => [50_000, 0], 'memo_paid' => [50_000, 0],
    ])->and(statementGrid(IncomeStatement::owner($this->leased->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::BILLED)))->toBe([
        'head_lease' => [50_000, 30_000], 'total_income' => [50_000, 30_000], 'net' => [50_000, 30_000], 'memo_paid' => [50_000, 0],
    ]);
});

test('an owner statement never reaches past the buildings it is given', function () {
    expect(IncomeStatement::isEmpty(IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, [$this->other->id])))->toBeTrue();
});

test('a second managed contract not yet finalised: posted fees stay, the month is pending, totals equal their cells', function () {
    activeOwnerContract(['owner_id' => $this->managed->owner_id, 'building_id' => $this->building->id, 'type' => 'managed',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [Unit::factory()->for($this->building)->create()]);

    $s = IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::COLLECTED);

    expect($s['fee_pending'])->toBe(['2026-03', '2026-04'])
        ->and($s['rows']['fee']['amounts'])->toBe(['2026-03' => 11_000, '2026-04' => null])
        ->and($s['rows']['fee']['total'])->toBe(11_000)->and($s['rows']['fee']['total'])->toBe(array_sum($s['rows']['fee']['amounts']))
        ->and($s['rows']['net']['amounts'])->toBe(['2026-03' => 69_000, '2026-04' => 0]);
});

test('an owner statement is the same for the building it is given, and says "Less management fee" without VAT registration', function () {
    CompanySetting::current()->forceFill(['vat_registered' => false])->save();
    $all = IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::COLLECTED);

    expect(IncomeStatement::owner($this->managed->owner_id, '2026-03-01', '2026-04-30', IncomeStatement::COLLECTED, [$this->building->id]))->toBe($all)
        ->and($all['rows']['fee']['label'])->toBe('Less management fee');
});

test('the income statement screen: last 12 complete months by default, one column per month, both bases, Excel audited', function () {
    $page = Livewire::actingAs($this->finance)->test(IncomeStatementReport::class)
        ->assertSet('from', '2025-04')->assertSet('to', '2026-03')->assertSet('basis', 'collected');

    // The fixture now has a second building (+80 rent, -25 head lease, -7 legal), so March's result is 263, not the plan's 208.
    $html = $page->set('from', '2026-03')->set('to', '2026-04')->html();
    expect($html)->toContain('03/2026')->toContain('04/2026')
        ->and(reportRowText($html, 'Not linked to a unit'))->toBe('Not linked to a unit 40.000 0.000 40.000')
        ->and(reportRowText($html, 'Result'))->toBe('Result 263.000 -12.000 251.000');

    // Billed head lease: 50 + the other building's 25 due in March, 30 in April (the plan's 50/30/80 predates the second building).
    expect(reportRowText($page->set('basis', 'billed')->html(), 'Head lease'))->toBe('Head lease 75.000 30.000 105.000');

    $page->call('export')->assertFileDownloaded('income-statement-2026-03-2026-04.xlsx');
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'income_statement')->sole()->properties->only(['from', 'to', 'basis', 'building', 'owner'])->all())
        ->toEqual(['from' => '2026-03', 'to' => '2026-04', 'basis' => 'billed', 'building' => null, 'owner' => null]);

    $page->set('from', '2025-06')->set('to', '2025-06')->assertSee('Nothing in this period.');
    $this->actingAs($this->finance)->get(route('reports.index'))->assertSee('Income statement');
});

test('a building-scoped user sees only their buildings: no unlinked line, no other building, no other building\'s owners', function () {
    $scoped = User::factory()->withTwoFactor()->create()->givePermissionTo('reports.financial');
    $scoped->buildings()->attach($this->building->id);

    $html = Livewire::actingAs($scoped)->test(IncomeStatementReport::class)->set('from', '2026-03')->set('to', '2026-04')
        ->assertDontSee('Not linked to a unit')->assertDontSee($this->other->code)->assertDontSee('Omar Outside')->assertSee('Leila Lessor')
        ->set('building', $this->other->id)->assertHasErrors('building')->assertOk()
        ->set('building', null)->html();

    expect(reportRowText($html, 'Result'))->toBe('Result 175.000 -12.000 163.000');
});

test('tampered inputs give a message, never a 500, and no export', function () {
    Livewire::actingAs($this->finance)->test(IncomeStatementReport::class)
        ->set('basis', 'cash')->assertHasErrors('basis')->assertOk()
        ->set('basis', 'billed')->set('from', '2025-01')->set('to', '2026-04')->assertHasErrors('to')->assertOk()   // 16 months
        ->set('from', '2026-04')->set('to', '2026-03')->assertHasErrors('to')
        ->set('owner', 999999)->assertHasErrors('owner')
        ->set('owner', null)->set('from', '1999-01')->assertHasErrors('from')->assertOk()
        ->call('export')->assertNoFileDownloaded();

    expect(Activity::where('event', 'report.exported')->count())->toBe(0);
});

test('owner scope on the screen narrows to that owner\'s contracts', function () {
    $html = Livewire::actingAs($this->finance)->test(IncomeStatementReport::class)->set('from', '2026-03')->set('to', '2026-04')
        ->set('owner', $this->leased->owner_id)->html();

    expect(reportRowText($html, 'Result'))->toBe('Result 60.000 0.000 60.000')->and($html)->not->toContain('Management fees');
});

test('tampered Livewire payloads of the wrong type (arrays, garbage) never 500', function () {
    $page = Livewire::actingAs($this->finance)->test(IncomeStatementReport::class);
    foreach (['from', 'to', 'basis'] as $prop) {
        $page->set($prop, ['x'])->assertOk()->assertHasErrors($prop)->set($prop, 'abc')->assertOk()->assertHasErrors($prop);
        $page->call('export')->assertNoFileDownloaded();
        $page->set('from', '2026-03')->set('to', '2026-04')->set('basis', 'collected');
    }
    foreach (['building', 'owner'] as $prop) {
        $page->set($prop, ['x'])->assertOk()->assertHasErrors($prop)->set($prop, 'abc')->assertOk()->assertHasErrors($prop)->set($prop, null)->assertHasNoErrors();
    }
    expect(Activity::where('event', 'report.exported')->count())->toBe(0);
});

test('bad URL parameters fall back to the defaults and show a statement', function () {
    Livewire::withQueryParams(['from' => 'zzz', 'to' => ['a'], 'basis' => 'cash', 'building' => 'abc', 'owner' => '999999'])->actingAs($this->finance)
        ->test(IncomeStatementReport::class)
        ->assertSet('from', '2025-04')->assertSet('to', '2026-03')->assertSet('basis', 'collected')->assertSet('building', null)->assertSet('owner', null)
        ->assertHasNoErrors();
    $this->actingAs($this->finance)->get(route('reports.income-statement', ['from' => ['x'], 'basis' => 'cash']))->assertOk()->assertSee('Result');
});

test('the statement table: header scopes, row headers, total cue, and the fee footnote only when fee_pending says so', function () {
    $s = ['months' => ['2026-03', '2026-04'], 'rows' => [
        'fee' => ['label' => 'Less management fee', 'kind' => 'line', 'amounts' => ['2026-03' => 11_000, '2026-04' => null], 'total' => 11_000],
        'net' => ['label' => 'Net', 'kind' => 'total', 'amounts' => ['2026-03' => 11_000, '2026-04' => 0], 'total' => 11_000],
    ]];
    $render = fn (array $st) => view('livewire.reports.partials.statement-table', ['statement' => $st])->render();

    $plain = $render($s);
    expect($plain)->toContain('scope="col"')->toContain('scope="row"')->toContain('aria-label="Net (total)"')->not->toContain('Fee not final');
    $pending = $render([...$s, 'fee_pending' => ['2026-03', '2026-04']]);
    expect($pending)->toContain('Fee not final until the owner statement for this month is finalised.')->toContain('11.000 *');
});

test('the owner screen lists owners, filters them by name, shows the fee as – until final, and exports audited', function () {
    $page = Livewire::actingAs($this->finance)->test(OwnerIncomeStatementReport::class)
        ->assertSee('Choose an owner to see their statement.')->assertSee('Leila Lessor')->assertSee('Mansour Manager')
        ->set('ownerSearch', 'leila')->assertSee('Leila Lessor')->assertDontSee('Mansour Manager')
        ->set('ownerSearch', '')->set('owner', $this->managed->owner_id)->set('from', '2026-03')->set('to', '2026-04');

    $html = $page->html();
    expect(reportRowText($html, 'Less management fee'))->toBe('Less management fee and VAT 11.000 – 11.000')
        ->and(reportRowText($html, 'Net income to owner'))->toBe('Net income to owner 69.000 0.000 69.000')
        ->and($html)->toContain('Fee not final until the owner statement for this month is finalised.');

    $page->call('export')->assertFileDownloaded('owner-income-statement-2026-03-2026-04.xlsx');
    expect(Activity::where('event', 'report.exported')->where('properties->report', 'owner_income_statement')->sole()->properties->only(['owner', 'basis'])->all())
        ->toEqual(['owner' => $this->managed->owner_id, 'basis' => 'collected']);

    $this->actingAs($this->finance)->get(route('reports.index'))->assertSee('Owner income statement');
});

test('an owner is listed only if the user can see one of their buildings; tampered owners give a message', function () {
    $scoped = User::factory()->withTwoFactor()->create()->givePermissionTo('reports.financial');
    $scoped->buildings()->attach($this->building->id);
    $outside = Owner::where('name_en', 'Omar Outside')->sole();
    $otherside = Owner::where('name_en', 'Olga Otherside')->sole();

    $page = Livewire::actingAs($scoped)->test(OwnerIncomeStatementReport::class)
        ->assertSee('Leila Lessor')->assertDontSee('Omar Outside')->assertDontSee('Olga Otherside')
        ->set('owner', $outside->id)->assertHasErrors('owner')->assertOk()
        ->set('owner', $otherside->id)->assertHasErrors('owner')->assertOk();
    expect($page->html())->not->toContain('Less head lease')->not->toContain('25.000');
    $page->set('owner', 999999)->assertHasErrors('owner')->assertOk()
        ->set('owner', null)->call('export')->assertHasErrors('owner')->assertNoFileDownloaded();
});

test('tampered owner payloads and URLs never 500 and never show another owner', function () {
    $page = Livewire::actingAs($this->finance)->test(OwnerIncomeStatementReport::class);
    foreach ([['x'], 'abc', '1e3', -1] as $bad) {
        $page->set('owner', $bad)->assertOk()->assertHasErrors('owner')->call('export')->assertNoFileDownloaded();
    }
    $page->set('ownerSearch', 'zzz')->assertOk();
    Livewire::withQueryParams(['owner' => ['x'], 'from' => 'zzz'])->actingAs($this->finance)->test(OwnerIncomeStatementReport::class)
        ->assertSet('owner', null)->assertSet('from', '2025-04')->assertHasNoErrors();
    $this->actingAs($this->finance)->get(route('reports.owner-income-statement', ['owner' => 'abc', 'basis' => 'cash']))->assertOk();
    expect(Activity::where('event', 'report.exported')->count())->toBe(0);
});

test('a pending month on the owner screen shows the posted fee with a marker and the footnote', function () {
    activeOwnerContract(['owner_id' => $this->managed->owner_id, 'building_id' => $this->building->id, 'type' => 'managed',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [Unit::factory()->for($this->building)->create()]);

    $html = Livewire::actingAs($this->finance)->test(OwnerIncomeStatementReport::class)
        ->set('owner', $this->managed->owner_id)->set('from', '2026-03')->set('to', '2026-04')->html();

    expect(reportRowText($html, 'Less management fee'))->toBe('Less management fee and VAT 11.000 * – 11.000')
        ->and($html)->toContain('Fee not final until the owner statement for this month is finalised.');
});

test('a leased owner on the screen: head lease earned and memo payments', function () {
    $html = Livewire::actingAs($this->finance)->test(OwnerIncomeStatementReport::class)
        ->set('owner', $this->leased->owner_id)->set('from', '2026-03')->set('to', '2026-04')->set('basis', 'billed')->html();

    expect(reportRowText($html, 'Head lease earned'))->toBe('Head lease earned 50.000 30.000 80.000')
        ->and(reportRowText($html, 'Memo: paid to owner'))->toBe('Memo: paid to owner 50.000 0.000 50.000')
        ->and($html)->not->toContain('Less management fee');
});
