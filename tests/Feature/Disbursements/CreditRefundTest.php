<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Billing\CustomerCredit;
use App\Billing\CustomerStatement;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Disbursement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    issuedInvoice($this->customer, [['net' => '100.000']]);
    $this->payment = app(RecordPayment::class)->handle($this->finance, $this->customer,
        ['received_on' => '2026-10-05', 'method' => 'bank_transfer', 'amount' => '160.000']); // 100 allocated, 60 credit
    $this->refund = fn (string $amount, array $over = []) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => $amount, 'method' => 'bank_transfer',
        'reference' => 'TRX-OUT-1', 'paid_on' => '2026-10-05', ...$over,
    ]);
});

test('a credit refund within the payment\'s credit is paid at once, numbered, and reduces the credit', function () {
    $out = ($this->refund)('40.000');

    expect($out->status->value)->toBe('paid')
        ->and($out->number)->toBe('PO-2026-000001')
        ->and($out->payee_type->value)->toBe('customer')
        ->and($out->payee_id)->toBe($this->customer->id)
        ->and($out->source_type)->toBe('payment')
        ->and($out->source_id)->toBe($this->payment->id)
        ->and($out->recorded_by)->toBe($this->finance->id)
        ->and($this->payment->fresh()->unallocatedFils())->toBe(20_000)
        ->and(CustomerCredit::fils($this->customer->id))->toBe(20_000)
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('a refund above the payment\'s credit is refused, never sent for approval', function () {
    ($this->refund)('50.000');

    expect(fn () => ($this->refund)('10.001'))->toThrow(ValidationException::class);
    expect(Disbursement::count())->toBe(1);
});

test('the statement shows the refund as a debit and still matches the cached figures', function () {
    ($this->refund)('60.000');

    $s = CustomerStatement::receivables($this->customer, '2026-10-01', '2026-10-31');

    expect(array_column($s['rows'], 'kind'))->toBe(['Invoice', 'Payment', 'Credit refund'])
        ->and($s['closing'])->toBe(0)
        ->and(CustomerCredit::fils($this->customer->id))->toBe(0);
});

test('a refunded payment cannot be reversed while its refund stands', function () {
    ($this->refund)('60.000');
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $approval = app(RequestPaymentReversal::class)->handle($this->finance, $this->payment, 'Recalled');

    expect(fn () => app(DecideApproval::class)->handle($management, $approval, true))->toThrow(ValidationException::class);
    expect($this->payment->fresh()->status->value)->toBe('confirmed');
});

test('only disbursements.manage records payments out; amounts, dates and payments are validated', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    expect(fn () => app(RecordDisbursement::class)->handle($management, ['purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '1', 'method' => 'cash', 'paid_on' => '2026-10-05']))
        ->toThrow(AuthorizationException::class);

    foreach ([['amount' => '0'], ['paid_on' => '2026-10-06'], ['method' => 'card'], ['payment_id' => 999999], ['purpose' => 'head_lease']] as $bad) {
        expect(fn () => ($this->refund)('1', $bad))->toThrow(ValidationException::class);
    }
});
