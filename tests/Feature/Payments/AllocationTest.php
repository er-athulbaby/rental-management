<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt (Task 9)
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
});

function pay(object $test, string $amount, array $allocations = []): Payment
{
    return app(RecordPayment::class)->handle($test->finance, $test->customer, [
        'received_on' => '2026-10-05', 'method' => 'bank_transfer', 'amount' => $amount, 'reference' => 'TRX-1', 'allocations' => $allocations,
    ]);
}

test('a partial payment splits across the lines by balance, to the fil, never above a line balance', function () {
    // Lines 300 / 200 / 100 (exempt); 500 000 fils split by largest remainder → 250.000 / 166.667 / 83.333.
    $invoice = issuedInvoice($this->customer, [['net' => '300.000'], ['net' => '200.000'], ['net' => '100.000']]);

    $payment = pay($this, '500.000');

    $parts = PaymentAllocation::query()->where('payment_id', $payment->id)->orderBy('invoice_line_id')->pluck('amount')->all();
    expect($parts)->toBe(['250.000', '166.667', '83.333'])
        ->and($payment->number)->toBe('RCP-2026-000001')
        ->and($invoice->fresh()->balance)->toBe('100.000')
        ->and($invoice->fresh()->allocated)->toBe('500.000')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(0);
});

test('by default the oldest due invoice is paid first and the rest stays as credit', function () {
    $newer = issuedInvoice($this->customer, [['net' => '400.000']], '2026-11-01');
    $older = issuedInvoice($this->customer, [['net' => '400.000']], '2026-10-01');

    pay($this, '500.000');

    expect($older->fresh()->balance)->toBe('0.000')
        ->and($newer->fresh()->balance)->toBe('300.000')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(0);

    pay($this, '350.000');
    expect($newer->fresh()->balance)->toBe('0.000')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(50_000);
});

test('Finance can name the invoices and amounts; over-allocating is refused', function () {
    $a = issuedInvoice($this->customer, [['net' => '400.000']], '2026-10-01');
    $b = issuedInvoice($this->customer, [['net' => '400.000']], '2026-11-01');

    pay($this, '300.000', [['invoice_id' => $b->id, 'amount' => '300.000']]);
    expect($a->fresh()->balance)->toBe('400.000')->and($b->fresh()->balance)->toBe('100.000');

    expect(fn () => pay($this, '100.000', [['invoice_id' => $b->id, 'amount' => '150.000']]))->toThrow(ValidationException::class);
    expect(fn () => pay($this, '500.000', [['invoice_id' => $a->id, 'amount' => '450.000']]))->toThrow(ValidationException::class); // above its balance
});

test('allocations carry their share of tax exactly; a full payment carries the whole line tax', function () {
    $invoice = issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard']]); // 110.000 incl. 10.000 tax

    pay($this, '33.333');
    pay($this, '76.667');

    $taxes = PaymentAllocation::query()->pluck('tax_amount')->map(fn ($t) => Fils::fromDecimal($t))->all();
    expect(array_sum($taxes))->toBe(10_000)->and($invoice->fresh()->balance)->toBe('0.000');
});

test('a deposit line allocation writes a received deposit movement with the line\'s owner contract', function () {
    $unit = Unit::factory()->create();
    $managed = activeOwnerContract(['building_id' => $unit->building_id, 'start_date' => '2026-01-01', 'end_date' => '2027-12-31'], [$unit]);
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30'], [$unit]);
    $au = $agreement->agreementUnits()->sole();
    issuedInvoice($this->customer, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $au]], '2026-10-01', $agreement);

    pay($this, '150.000');

    $movement = DepositMovement::sole();
    expect($movement->type->value)->toBe('received')
        ->and($movement->amount)->toBe('150.000')
        ->and($movement->agreement_unit_id)->toBe($au->id)
        ->and($movement->owner_contract_id)->toBe($managed->id);
});

test('customer credit is used automatically when a new invoice is issued', function () {
    pay($this, '250.000'); // nothing owed yet → all credit
    expect(CustomerCredit::fils($this->customer->id))->toBe(250_000);

    $invoice = issuedInvoice($this->customer, [['net' => '400.000']]);

    expect($invoice->fresh()->balance)->toBe('150.000')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(0);
});

test('auto-allocation can be switched off for an issue', function () {
    pay($this, '250.000');
    $invoice = (new Invoice)->forceFill(['type' => 'manual', 'customer_id' => $this->customer->id, 'issue_date' => '2026-10-05', 'due_date' => '2026-10-05', 'status' => 'draft', 'subtotal' => '100.000', 'tax_total' => '0.000', 'total' => '100.000']);
    $invoice->save();
    $invoice->lines()->create(['charge_type' => 'other', 'description' => 'x', 'net' => '100.000', 'tax_category' => 'exempt', 'tax_rate' => '0.00', 'tax_amount' => '0.000', 'total' => '100.000']);

    app(IssueInvoice::class)->handle($invoice, null, autoAllocate: false);

    expect($invoice->fresh()->balance)->toBe('100.000')->and(CustomerCredit::fils($this->customer->id))->toBe(250_000);
});

test('only payments.manage holders record payments; amounts and dates are validated', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    expect(fn () => app(RecordPayment::class)->handle($pm, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '10']))
        ->toThrow(AuthorizationException::class);

    foreach ([['amount' => '0'], ['amount' => '1.2345'], ['received_on' => '2026-10-06'], ['method' => 'cheque'], ['method' => 'deposit_applied']] as $bad) {
        expect(fn () => app(RecordPayment::class)->handle($this->finance, $this->customer, [...['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '10'], ...$bad]))
            ->toThrow(ValidationException::class);
    }
});
