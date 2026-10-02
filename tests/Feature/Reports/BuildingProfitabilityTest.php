<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Livewire\OwnerPayables\Index as PayablesIndex;
use App\Livewire\Reports\BuildingProfitabilityReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use App\Reports\BuildingProfitability;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    [$owned, $leasedUnit, $managedUnit] = Unit::factory()->for($this->building)->count(3)->create()->all();
    $leased = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'leased', 'rent_amount' => '50.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-03-01', 'end_date' => '2027-02-28'], [$leasedUnit]);
    $managed = activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [$managedUnit]);

    // Head lease: one payable of 50, paid 5 March.
    $payable = (new OwnerPayable)->forceFill(['owner_contract_id' => $leased->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'due_date' => '2026-03-01', 'amount' => '50.000', 'status' => 'scheduled']);
    $payable->save();
    app(RecordDisbursement::class)->handle($this->finance, ['purpose' => 'head_lease', 'owner_payable_id' => $payable->id, 'amount' => '50.000', 'method' => 'bank_transfer', 'paid_on' => '2026-03-05']);

    // Rent of 100 + VAT on each unit and a deposit on the owned one, all paid on 10 March.
    $customer = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$owned, $leasedUnit, $managedUnit]);
    $au = $agreement->agreementUnits()->orderBy('id')->get();
    issuedInvoice($customer, [
        ['net' => '100.000', 'tax' => 'standard', 'au' => $au[0]], ['net' => '100.000', 'tax' => 'standard', 'au' => $au[1]],
        ['net' => '100.000', 'tax' => 'standard', 'au' => $au[2]], ['type' => 'deposit', 'net' => '200.000', 'au' => $au[0]],
    ], '2026-03-01', $agreement);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(RecordPayment::class)->handle($this->finance, $customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '530.000']);

    // Expenses: 30 + 3 VAT to the company (counts 30, VAT registered); 20 to the owner (not the company's cost).
    app(RecordExpense::class)->handle($this->finance, ['building_id' => $this->building->id, 'category' => 'cleaning', 'description' => 'Lobby', 'expense_date' => '2026-03-10', 'net' => '30.000', 'tax_amount' => '3.000', 'charge_to' => 'company']);
    app(RecordExpense::class)->handle($this->finance, ['building_id' => $this->building->id, 'unit_id' => $managedUnit->id, 'category' => 'maintenance', 'description' => 'Pump', 'expense_date' => '2026-03-10', 'net' => '20.000', 'charge_to' => 'owner']);

    // The managed contract's March statement, finalised: fee 10.000 net.
    $this->travelTo(CarbonImmutable::parse('2026-04-01 04:00', 'Asia/Bahrain'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));
    app(DecideApproval::class)->handle($management, app(SubmitOwnerStatement::class)->handle($this->finance, OwnerStatement::sole()), true);
    $this->travelTo(CarbonImmutable::parse('2026-04-02 09:00', 'Asia/Bahrain'));
});

test('income is the owned and leased units\' collections net of VAT plus managed fees; costs are head lease and company expenses', function () {
    expect(BuildingProfitability::for($this->building, '2026-03-01', '2026-03-31'))->toBe([
        'collected' => 200_000,  // owned + leased rent, net of VAT; managed rent and the deposit excluded
        'fees' => 10_000,
        'income' => 210_000,
        'billed' => 210_000,     // 200 billed + the fee
        'head_lease' => 50_000,
        'expenses' => 30_000,
        'costs' => 80_000,
        'result' => 130_000,
    ])->and(BuildingProfitability::for($this->building, '2026-04-01', '2026-04-30')['result'])->toBe(0);
});

test('the report page shows each building and exports to Excel, audited', function () {
    Livewire::actingAs($this->finance)->test(BuildingProfitabilityReport::class)
        ->set('from', '2026-03-01')->set('to', '2026-03-31')
        ->assertSee($this->building->code)->assertSee('130.000')
        ->call('export')->assertFileDownloaded();

    Livewire::actingAs($this->finance)->test(PayablesIndex::class)->call('export')->assertFileDownloaded();

    expect(Activity::where('event', 'report.exported')->count())->toBe(2);
});
