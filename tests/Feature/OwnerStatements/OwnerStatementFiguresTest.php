<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\Payments\RecordPayment;
use App\Billing\OwnerStatementCalculator;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerCharge;
use App\Models\OwnerStatement;
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
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->managed = fn (array $over = []) => activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000', ...$over], [$this->unit]);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->rent = fn (string $net, string $due) => issuedInvoice($this->customer, [['net' => $net, 'tax' => 'standard', 'au' => $this->agreement->agreementUnits()->sole()]], $due, $this->agreement);
    $this->pay = fn (string $amount, string $on) => app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => $on, 'method' => 'cash', 'amount' => $amount]);
    $this->draft = function (string $month) {
        $this->travelTo(CarbonImmutable::parse($month.'-01 04:00', 'Asia/Bahrain')->addMonth());

        return app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse($month.'-01'));
    };
});

test('§14 flow 6: collections net of VAT, less expenses and the fee; a late entry lands on the next statement', function () {
    $contract = ($this->managed)();
    ($this->rent)('100.000', '2026-03-01');
    ($this->pay)('110.000', '2026-03-10');
    app(RecordExpense::class)->handle($this->finance, ['building_id' => $this->building->id, 'unit_id' => $this->unit->id, 'category' => 'maintenance', 'description' => 'Pump', 'expense_date' => '2026-03-10', 'net' => '20.000', 'tax_amount' => '2.000', 'charge_to' => 'owner']);
    ($this->rent)('100.000', '2026-03-15');

    expect(($this->draft)('2026-03'))->toBe(1);
    $march = OwnerStatement::where('owner_contract_id', $contract->id)->sole();
    expect([$march->opening_balance, $march->fee_base, $march->fee_amount, $march->fee_tax, $march->closing_balance, $march->status->value])
        ->toBe(['0.000', '100.000', '10.000', '1.000', '67.000', 'draft'])  // 100 − 22 − 10 − 1
        ->and($march->cutoff_at->toDateTimeString())->toBe('2026-03-31 23:59:59');

    // Received on 31 March but recorded on 2 April: April's statement, showing its March date.
    $this->travelTo(CarbonImmutable::parse('2026-04-02 09:00', 'Asia/Bahrain'));
    ($this->pay)('110.000', '2026-03-31');
    ($this->draft)('2026-04');
    $april = OwnerStatement::where('owner_contract_id', $contract->id)->where('period_start', '2026-04-01')->sole();
    $figures = OwnerStatementCalculator::compute($april);

    expect($april->opening_balance)->toBe('67.000')->and($april->closing_balance)->toBe('156.000') // 67 + 100 − 11
        ->and($figures['entries']->map(fn ($e) => [$e['kind'], $e['date'], $e['amount']])->all())->toBe([['collection', '2026-03-31', 100_000]]);
});

test('percent billed: a negative base gives no fee and carries into next month', function () {
    $contract = ($this->managed)(['fee_type' => 'percent_billed']);
    $march = ($this->rent)('100.000', '2026-03-01');
    ($this->draft)('2026-03');

    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00', 'Asia/Bahrain'));
    $cn = app(SaveCreditNote::class)->handle($this->finance, $march, null, ['reason' => 'Rent waived', 'lines' => [['credited_line_id' => $march->lines->sole()->id, 'amount' => '110.000']]]);
    app(DecideApproval::class)->handle($this->management, app(SubmitCreditNote::class)->handle($this->finance, $cn), true);
    ($this->draft)('2026-04');

    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00', 'Asia/Bahrain'));
    ($this->rent)('300.000', '2026-05-01');
    ($this->draft)('2026-05');

    $s = OwnerStatement::where('owner_contract_id', $contract->id)->orderBy('period_start')->get();
    expect($s->map(fn ($x) => [$x->fee_base, $x->fee_amount])->all())->toBe([
        ['100.000', '10.000'],
        ['-100.000', '0.000'],
        ['200.000', '20.000'], // 300 − the 100 carried
    ]);
});

test('a fixed fee is prorated for the days the contract is active in the month', function () {
    $contract = ($this->managed)(['fee_type' => 'fixed', 'fee_value' => '300.000', 'start_date' => '2026-03-16']);
    ($this->draft)('2026-03');

    $march = OwnerStatement::where('owner_contract_id', $contract->id)->sole();
    // 16 days on actual/365: 300 000 × 16 × 12 / 365 = 157 808.2 → 157.808; VAT 15.781.
    expect([$march->fee_base, $march->fee_amount, $march->fee_tax, $march->closing_balance])->toBe(['157.808', '157.808', '15.781', '-173.589']);
});

test('drafts: managed contracts active in the month or with new entries; once per month; nightly at 04:00 on the 1st', function () {
    $active = ($this->managed)();
    $ended = function () {
        $c = ($this->managed)(['start_date' => '2025-01-01', 'end_date' => '2026-01-31']);
        $c->forceFill(['status' => 'ended'])->save();

        return $c;
    };
    $endedQuiet = $ended();
    $endedBusy = $ended();
    OwnerCharge::create(['owner_contract_id' => $endedBusy->id, 'type' => 'opening_balance', 'net' => '5.000', 'tax_amount' => '0.000', 'amount' => '5.000', 'posted_at' => now(), 'created_by' => $this->finance->id]);
    activeOwnerContract(['building_id' => $this->building->id, 'type' => 'leased', 'rent_amount' => '1.000', 'payment_frequency' => 'monthly', 'fee_type' => null, 'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], []);

    expect(($this->draft)('2026-03'))->toBe(2)
        ->and(OwnerStatement::pluck('owner_contract_id')->sort()->values()->all())->toBe([$active->id, $endedBusy->id])
        ->and(OwnerStatement::where('owner_contract_id', $endedBusy->id)->value('created_by'))->toBe($endedBusy->created_by) // plan ruling 8
        ->and(app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01')))->toBe(0)
        ->and(OwnerStatement::where('owner_contract_id', $endedQuiet->id)->exists())->toBeFalse()
        ->and(scheduledEvent('rms:owner-statements:draft')->expression)->toBe('0 4 1 * *');
});

test('an earlier month is never drafted behind a later statement (§7.9 windows chain forward)', function () {
    $contract = ($this->managed)();
    ($this->draft)('2026-03');

    expect(app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-02-01')))->toBe(0)
        ->and($contract->statements()->pluck('period_start')->map->toDateString()->all())->toBe(['2026-03-01']);
});

test('statements are never deleted and a finalised one never changes', function () {
    $contract = ($this->managed)();
    ($this->draft)('2026-03');
    $id = OwnerStatement::where('owner_contract_id', $contract->id)->value('id');

    expect(fn () => DB::table('owner_statements')->where('id', $id)->delete())->toThrow(QueryException::class, 'owner_statements cannot be deleted');
    DB::table('owner_statements')->where('id', $id)->update(['status' => 'pending_approval']);
    DB::table('owner_statements')->where('id', $id)->update(['status' => 'finalised', 'number' => 'OS-T-1', 'finalised_at' => now(), 'finalised_by' => $this->management->id]);
    expect(fn () => DB::table('owner_statements')->where('id', $id)->update(['closing_balance' => '1.000']))->toThrow(QueryException::class, 'owner_statements: a finalised statement never changes')
        ->and(fn () => DB::table('owner_statements')->where('id', $id)->update(['status' => 'draft']))->toThrow(QueryException::class);
});
