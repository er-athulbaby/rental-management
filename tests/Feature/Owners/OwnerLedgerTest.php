<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\Payments\RecordPayment;
use App\Billing\OwnerLedger;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerCharge;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $building = Building::factory()->create();
    $this->unit = Unit::factory()->for($building)->create();
    $this->contract = fn (string $heldBy) => activeOwnerContract(['building_id' => $building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => $heldBy, 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [$this->unit]);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->au = $this->agreement->agreementUnits()->sole();
});

test('collections post net of VAT, deposits only when the owner holds them, expenses and their reversal at their own times', function () {
    $contract = ($this->contract)('owner');
    issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard', 'au' => $this->au], ['type' => 'deposit', 'net' => '200.000', 'au' => $this->au]], '2026-03-01', $this->agreement);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '310.000']);
    $expense = app(RecordExpense::class)->handle($this->finance, ['building_id' => $this->unit->building_id, 'unit_id' => $this->unit->id, 'category' => 'maintenance', 'description' => 'Pump', 'expense_date' => '2026-03-10', 'net' => '20.000', 'tax_amount' => '2.000', 'charge_to' => 'owner']);
    $this->travel(1)->days();
    app(ReverseExpense::class)->handle($this->finance, $expense, 'Duplicate');

    $entries = OwnerLedger::entries($contract->fresh());
    expect($entries->map(fn ($e) => [$e['kind'], $e['amount']])->all())->toEqualCanonicalizing([
        ['collection', 100_000],        // 110.000 less 10.000 VAT
        ['deposit_received', 200_000],
        ['expense', -22_000],           // plan ruling 6: the total
        ['expense_reversal', 22_000],
    ])->and(OwnerLedger::balance($contract->fresh()))->toBe(300_000)
        ->and(OwnerLedger::balance($contract->fresh(), CarbonImmutable::parse('2026-03-10 23:59:59')))->toBe(278_000)
        ->and(OwnerLedger::entries($contract->fresh(), CarbonImmutable::parse('2026-03-10 23:59:59'))->pluck('kind')->all())->toBe(['expense_reversal']);
});

test('deposits held by the company stay off the owner ledger; owner charges post signed', function () {
    $contract = ($this->contract)('company');
    issuedInvoice($this->customer, [['type' => 'deposit', 'net' => '200.000', 'au' => $this->au]], '2026-03-01', $this->agreement);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '200.000']);
    OwnerCharge::create(['owner_contract_id' => $contract->id, 'type' => 'opening_balance', 'net' => '-50.000', 'tax_amount' => '0.000', 'amount' => '-50.000', 'posted_at' => now(), 'created_by' => $this->finance->id]);

    expect(OwnerLedger::entries($contract)->map(fn ($e) => [$e['kind'], $e['amount']])->all())->toBe([['opening_balance', -50_000]]);
});

test('owner charges are write-once', function () {
    $contract = ($this->contract)('company');
    $charge = OwnerCharge::create(['owner_contract_id' => $contract->id, 'type' => 'opening_balance', 'net' => '50.000', 'tax_amount' => '0.000', 'amount' => '50.000', 'posted_at' => now(), 'created_by' => $this->finance->id]);

    expect(fn () => DB::table('owner_charges')->where('id', $charge->id)->update(['amount' => '1.000']))->toThrow(QueryException::class, 'owner_charges are write-once')
        ->and(fn () => DB::table('owner_charges')->where('id', $charge->id)->delete())->toThrow(QueryException::class, 'owner_charges are write-once')
        ->and(fn () => OwnerCharge::create(['owner_contract_id' => $contract->id, 'type' => 'management_fee', 'net' => '5.000', 'tax_amount' => '0.500', 'amount' => '5.500', 'posted_at' => now(), 'created_by' => $this->finance->id]))
        ->toThrow(QueryException::class); // a fee is always owed by the owner and belongs to a statement
});
