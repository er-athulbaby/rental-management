<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Cheques\ClearCheque;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Enums\RoleName;
use App\Ledger\Poster;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\LedgerSetting;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

afterEach(function () {
    LedgerSetting::current()->forceFill(['start_date' => null, 'locked_until' => null])->save();
});

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['vat_registered' => false, 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    ledgerStart('2026-10-01');
    $this->building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->building);
    $this->management = matrixUser(RoleName::Management, $this->building);
    $this->tenant = Customer::factory()->create();
    $unit = Unit::factory()->for($this->building)->create();
    activeOwnerContract(['building_id' => $this->building->id, 'type' => 'leased', 'rent_amount' => '300.000', 'payment_frequency' => 'monthly', 'fee_type' => null,
        'fee_value' => null, 'deposits_held_by' => null, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    $this->agreement = activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    $this->au = $this->agreement->agreementUnits->sole();
    $this->rent = issuedInvoice($this->tenant, [['net' => '440.000', 'au' => $this->au]], '2026-10-01', $this->agreement); // Dr 1100 / Cr 4000 440.000
    $this->pay = fn (array $data) => app(RecordPayment::class)->handle($this->finance, $this->tenant, ['received_on' => '2026-10-03', ...$data]);
});

test('a receipt debits its method\'s account and credits Tenant credit; what it pays moves to receivables on the receipt date', function () {
    $payment = ($this->pay)(['method' => 'cash', 'amount' => '500.000']);
    $allocation = $payment->allocations()->sole();

    expect(postedLines('payment', $payment->id))->toBe(['1000 500.000 0.000', '2300 0.000 500.000'])
        ->and(journalFor('payment', $payment->id)->date->toDateString())->toBe('2026-10-03')
        ->and(journalFor('payment', $payment->id)->lines->first()->customer_id)->toBe($this->tenant->id)
        ->and(postedLines('allocation', $allocation->id))->toBe(['2300 440.000 0.000', '1100 0.000 440.000'])
        ->and(journalFor('allocation', $allocation->id)->date->toDateString())->toBe('2026-10-03')
        ->and(ledgerBalances())->toBe([1000 => '500.000', 2300 => '-60.000', 4000 => '-440.000']);
});

test('a split receipt debits each part\'s account', function () {
    $payment = ($this->pay)(['tenders' => [['method' => 'card', 'amount' => '300.000'], ['method' => 'cash', 'amount' => '200.000']]]);

    expect(postedLines('payment', $payment->id))->toBe(['1020 300.000 0.000', '1000 200.000 0.000', '2300 0.000 500.000']);
});

test('a cheque posts nothing while held or deposited and debits Bank when it clears, on the clearing date', function () {
    $cheque = (new Cheque)->forceFill(['direction' => 'received', 'customer_id' => $this->tenant->id, 'agreement_id' => $this->agreement->id, 'invoice_id' => $this->rent->id,
        'cheque_no' => '000123', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-01', 'amount' => '440.000', 'status' => 'held', 'created_by' => $this->finance->id]);
    $cheque->save();
    $cheque->forceFill(['status' => 'deposited', 'deposited_on' => '2026-10-04'])->save();

    expect(Journal::query()->where('source_type', '<>', 'invoice')->count())->toBe(0);

    $payment = app(ClearCheque::class)->handle($this->finance, $cheque, '2026-10-05');

    expect(postedLines('payment', $payment->id))->toBe(['1010 440.000 0.000', '2300 0.000 440.000'])
        ->and(journalFor('payment', $payment->id)->date->toDateString())->toBe('2026-10-05');
});

test('credit applied to a later invoice moves on the day it is applied', function () {
    ($this->pay)(['method' => 'bank_transfer', 'amount' => '500.000']); // 60.000 stays as credit
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'Asia/Bahrain'));
    $later = issuedInvoice($this->tenant, [['net' => '50.000', 'type' => 'utilities', 'au' => $this->au]], '2026-10-09', $this->agreement);
    $allocation = PaymentAllocation::query()->whereHas('line', fn ($q) => $q->where('invoice_id', $later->id))->sole();

    expect(postedLines('allocation', $allocation->id))->toBe(['2300 50.000 0.000', '1100 0.000 50.000'])
        ->and(journalFor('allocation', $allocation->id)->date->toDateString())->toBe('2026-10-09');
});

test('a reversed receipt posts the exact opposite on the reversal day, and so do its cut allocations', function () {
    $payment = ($this->pay)(['method' => 'cash', 'amount' => '500.000']);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Asia/Bahrain'));
    app(DecideApproval::class)->handle($this->management, app(RequestPaymentReversal::class)->handle($this->finance, $payment, 'Counterfeit notes'), true);
    $cut = PaymentAllocation::query()->where('payment_id', $payment->id)->whereNotNull('reverses_allocation_id')->sole();

    expect(postedLines('payment_reversal', $payment->id))->toBe(['1000 0.000 500.000', '2300 500.000 0.000'])
        ->and(journalFor('payment_reversal', $payment->id)->date->toDateString())->toBe('2026-10-08')
        ->and(postedLines('allocation', $cut->id))->toBe(['2300 0.000 440.000', '1100 440.000 0.000'])
        ->and(journalFor('allocation', $cut->id)->date->toDateString())->toBe('2026-10-08')
        ->and(ledgerBalances())->toBe([1100 => '440.000', 4000 => '-440.000']);
});

test('a deposit applied by a settlement leaves Tenant deposits held for the tenant\'s credit, then pays the named rent', function () {
    $deposit = issuedInvoice($this->tenant, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $this->au]], '2025-12-01', $this->agreement, 'deposit');
    ($this->pay)(['method' => 'cash', 'amount' => '400.000', 'allocations' => [['invoice_id' => $deposit->id, 'amount' => '400.000']]]);
    $this->au->forceFill(['move_out_date' => '2026-10-04'])->save();
    $settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($this->agreement, [$this->au->id], $this->finance));
    app(SaveSettlementDeductions::class)->handle($this->finance, $settlement, [
        ['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'October rent', 'amount' => '300.000', 'invoice_line_id' => $this->rent->lines->sole()->id],
    ]);
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $settlement->fresh()), true);
    $payment = $settlement->fresh()->payment;

    expect($payment->method->value)->toBe('deposit_applied')
        ->and(postedLines('invoice', $deposit->id))->toBe(['1100 400.000 0.000', '2000 0.000 400.000'])
        ->and(postedLines('payment', $payment->id))->toBe(['2000 300.000 0.000', '2300 0.000 300.000'])
        ->and(postedLines('allocation', $payment->allocations()->sole()->id))->toBe(['2300 300.000 0.000', '1100 0.000 300.000']);
});

test('a source that is not pending takes no lock on the settings row', function () {
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });

    expect(app(Poster::class)->post('invoice', $this->rent->id))->toBeNull(); // already posted
    $this->rent->forceFill(['allocated' => $this->rent->allocated])->save(); // a watched save that changes nothing postable

    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'for update')))->toBeFalse();
});
