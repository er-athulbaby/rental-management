<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Cheques\ClearCheque;
use App\Actions\Cheques\DepositCheques;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Actions\Payments\ReverseAllocations;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Livewire\Payments\Show;
use App\Models\Approval;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt (Task 9)
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $this->invoice = issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard'], ['net' => '50.000']]); // 160.000
    $this->payment = app(RecordPayment::class)->handle($this->finance, $this->customer,
        ['received_on' => '2026-10-05', 'method' => 'bank_transfer', 'amount' => '200.000']); // 160 allocated, 40 credit
});

test('an approved reversal unwinds every allocation, tax included, and the credit', function () {
    $approval = app(RequestPaymentReversal::class)->handle($this->finance, $this->payment, 'Transfer recalled by the bank');
    expect($this->payment->fresh()->status->value)->toBe('confirmed'); // nothing happens until approval

    app(DecideApproval::class)->handle($this->management, $approval, true);

    $payment = $this->payment->fresh();
    expect($payment->status->value)->toBe('reversed')
        ->and($payment->reversed_at)->not->toBeNull()
        ->and($this->invoice->fresh()->balance)->toBe('160.000')
        ->and($this->invoice->fresh()->lines->every(fn ($l) => $l->allocated === '0.000'))->toBeTrue()
        ->and(PaymentAllocation::where('amount', '<', 0)->count())->toBe(2)
        ->and((string) PaymentAllocation::sum('amount'))->toBe('0.000')
        ->and((string) PaymentAllocation::sum('tax_amount'))->toBe('0.000')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(0);
});

test('a reversal after a later partial de-allocation reverses only what is still live', function () {
    // Reverse half of one allocation by hand through the internal Action, then reverse the payment.
    $allocation = PaymentAllocation::query()->orderBy('id')->first();
    DB::transaction(function () use ($allocation) {
        Customer::query()->lockForUpdate()->findOrFail($this->customer->id);
        app(ReverseAllocations::class)->handle([['allocation' => $allocation, 'amount' => 10_000]], $this->finance);
    });
    expect($allocation->fresh()->liveFils())->toBe(Fils_from('100.000'));

    app(DecideApproval::class)->handle($this->management, app(RequestPaymentReversal::class)->handle($this->finance, $this->payment, 'x'), true);

    expect((string) PaymentAllocation::where('reverses_allocation_id', $allocation->id)->sum('amount'))->toBe('-110.000');
});

test('rejecting leaves the payment unchanged; a pending reversal blocks a second request', function () {
    $approval = app(RequestPaymentReversal::class)->handle($this->finance, $this->payment, 'x');
    expect(fn () => app(RequestPaymentReversal::class)->handle($this->finance, $this->payment, 'again'))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, false, 'Not recalled');

    expect($this->payment->fresh()->status->value)->toBe('confirmed')
        ->and($this->invoice->fresh()->balance)->toBe('0.000');
});

test('a deposit payment reversal writes a negative received movement', function () {
    $unit = Unit::factory()->create();
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30'], [$unit]);
    $deposit = issuedInvoice($this->customer, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $agreement->agreementUnits()->sole()]], '2026-10-01', $agreement);
    // The 40.000 credit auto-allocated to the deposit on issue.
    expect((string) DepositMovement::sum('amount'))->toBe('40.000');

    app(DecideApproval::class)->handle($this->management, app(RequestPaymentReversal::class)->handle($this->finance, $this->payment, 'x'), true);

    expect((string) DepositMovement::sum('amount'))->toBe('0.000')
        ->and(DepositMovement::where('amount', '<', 0)->sole()->type->value)->toBe('received')
        ->and($deposit->fresh()->balance)->toBe('400.000');
});

test('only payments.manage requests; reversed and deposit-applied payments cannot be reversed; the requester cannot approve', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    expect(fn () => app(RequestPaymentReversal::class)->handle($pm, $this->payment, 'x'))->toThrow(AuthorizationException::class);
    expect(fn () => app(RequestPaymentReversal::class)->handle($this->finance, $this->payment, ''))->toThrow(ValidationException::class);

    $approval = app(RequestPaymentReversal::class)->handle($this->finance, $this->payment, 'x');
    $dual = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance)->assignRole(RoleName::Management);
    $own = app(RequestPaymentReversal::class)->handle($dual, app(RecordPayment::class)->handle($dual, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '5']), 'x');
    expect(fn () => app(DecideApproval::class)->handle($dual, $own, true))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, true);
    expect(fn () => app(RequestPaymentReversal::class)->handle($this->finance, $this->payment->fresh(), 'x'))->toThrow(ValidationException::class);

    $applied = $this->payment->replicate(['number'])->forceFill(['number' => 'RCP-TEST', 'method' => 'deposit_applied', 'status' => 'confirmed', 'reversed_at' => null]);
    $applied->save();
    expect(fn () => app(RequestPaymentReversal::class)->handle($this->finance, $applied, 'x'))->toThrow(ValidationException::class);
});

test('Finance requests a reversal from the payment page', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['payment' => $this->payment])
        ->set('reversalReason', 'Bank recalled the transfer')
        ->call('requestReversal')
        ->assertHasNoErrors()
        ->assertSee('Reversal waiting for approval');

    expect(Approval::sole()->action->value)->toBe('payment.reverse');
});

test('a second reversal request while one is pending shows the error in the modal', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['payment' => $this->payment])
        ->set('reversalReason', 'First')
        ->call('requestReversal')
        ->set('reversalReason', 'Second')
        ->call('requestReversal')
        ->assertHasErrors(['reversalReason']);

    expect(Approval::count())->toBe(1);
});

test('a cheque payment is reversed only by bouncing its cheque', function () {
    $customer = Customer::factory()->create();
    $cheque = app(RecordCheques::class)->handle($this->finance, $customer, null, [['cheque_no' => '5', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-05', 'amount' => '5']])->sole();
    app(DepositCheques::class)->handle($this->finance, [$cheque->id], '2026-10-05');
    $payment = app(ClearCheque::class)->handle($this->finance, $cheque->fresh(), '2026-10-05');

    expect(fn () => app(RequestPaymentReversal::class)->handle($this->finance, $payment, 'Typo'))->toThrow(ValidationException::class);
});
