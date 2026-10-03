<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Cheques\ClearIssuedCheque;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $payment = app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '50.000']);
    $this->refund = app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'credit_refund', 'payment_id' => $payment->id, 'amount' => '50.000', 'method' => 'cheque', 'paid_on' => '2026-10-05',
        'cheque_no' => '000123', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-05',
    ]);
});

test('an approved reversal of a credit refund cancels its cheque and returns the credit', function () {
    expect(CustomerCredit::fils($this->customer->id))->toBe(0);

    $approval = app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, 'Cheque lost in the post');
    expect($this->refund->fresh()->status->value)->toBe('paid'); // nothing moves until approval

    app(DecideApproval::class)->handle($this->management, $approval, true);

    $out = $this->refund->fresh();
    expect($out->status->value)->toBe('reversed')
        ->and($out->reversed_at)->not->toBeNull()
        ->and(Cheque::sole()->status->value)->toBe('cancelled')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(50_000);
});

test('a cleared issued cheque is not cancelled; rejecting changes nothing; reversals are one-way', function () {
    app(ClearIssuedCheque::class)->handle($this->finance, Cheque::sole(), '2026-10-05');
    $approval = app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, 'x');
    expect(fn () => app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, 'again'))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, false, 'Paid correctly');
    expect($this->refund->fresh()->status->value)->toBe('paid');

    app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, 'Bank returned it'), true);
    expect(Cheque::sole()->status->value)->toBe('cleared') // a cleared cheque stays as the bank saw it
        ->and($this->refund->fresh()->status->value)->toBe('reversed');
    expect(fn () => app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund->fresh(), 'x'))->toThrow(ValidationException::class);
});

test('only disbursements.manage requests; a reason is required; only paid rows reverse', function () {
    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    expect(fn () => app(RequestDisbursementReversal::class)->handle($pm, $this->refund, 'x'))->toThrow(AuthorizationException::class);
    expect(fn () => app(RequestDisbursementReversal::class)->handle($this->finance, $this->refund, ' '))->toThrow(ValidationException::class);
});
