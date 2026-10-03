<?php

use App\Actions\Cheques\ClearIssuedCheque;
use App\Actions\Cheques\RecordCheques;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->customer = Customer::factory()->create();
    $this->payment = app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '90.000']);
});

test('a refund paid by cheque writes an issued cheque linked both ways, then the cheque clears', function () {
    $out = app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '90.000', 'method' => 'cheque',
        'paid_on' => '2026-10-05', 'cheque_no' => '000777', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-07',
    ]);

    $cheque = Cheque::sole();
    expect($cheque->direction->value)->toBe('issued')
        ->and($cheque->status->value)->toBe('issued')
        ->and($cheque->customer_id)->toBe($this->customer->id)
        ->and($cheque->amount)->toBe('90.000')
        ->and($cheque->disbursement_id)->toBe($out->id)
        ->and($out->cheque_id)->toBe($cheque->id);

    app(ClearIssuedCheque::class)->handle($this->finance, $cheque, '2026-10-05');
    expect($cheque->fresh()->status->value)->toBe('cleared')->and($cheque->fresh()->cleared_on->toDateString())->toBe('2026-10-05');
});

test('a cheque payment out needs the cheque details', function () {
    expect(fn () => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'credit_refund', 'payment_id' => $this->payment->id, 'amount' => '1', 'method' => 'cheque', 'paid_on' => '2026-10-05',
    ]))->toThrow(ValidationException::class);
});

test('a received cheque cannot be cleared as an issued one', function () {
    $received = app(RecordCheques::class)->handle($this->finance, $this->customer, null, [['cheque_no' => '1', 'bank_name' => 'BBK', 'cheque_date' => '2026-10-05', 'amount' => '5']])->sole();

    expect(fn () => app(ClearIssuedCheque::class)->handle($this->finance, $received, '2026-10-05'))->toThrow(ValidationException::class);
});
