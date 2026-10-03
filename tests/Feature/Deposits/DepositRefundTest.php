<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
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
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30'], [Unit::factory()->create()]);
    $this->au = $agreement->agreementUnits()->sole();
    issuedInvoice($this->customer, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $this->au]], '2025-10-01', $agreement);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '400.000']);
    $this->settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($agreement, [$this->au->id], $this->finance));
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement), true);
    $this->pay = fn (string $amount) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'deposit_refund', 'deposit_settlement_id' => $this->settlement->id, 'amount' => $amount, 'method' => 'bank_transfer', 'paid_on' => '2026-10-05',
    ]);
});

test('refunds up to the settlement\'s refund write refunded movements and complete the settlement', function () {
    ($this->pay)('150.000');
    expect($this->settlement->fresh()->status->value)->toBe('approved')
        ->and(DepositMovement::heldFils($this->au->id))->toBe(250_000);

    $last = ($this->pay)('250.000');
    expect($last->payee_id)->toBe($this->customer->id)
        ->and($this->settlement->fresh()->status->value)->toBe('completed')
        ->and(DepositMovement::where('type', 'refunded')->count())->toBe(2)
        ->and(DepositMovement::heldFils($this->au->id))->toBe(0)
        ->and(app(IntegrityCheck::class)->run())->toBe([]);

    expect(fn () => ($this->pay)('0.001'))->toThrow(ValidationException::class);
});

test('reversing a deposit refund puts the deposit back and reopens the settlement', function () {
    $out = ($this->pay)('400.000');
    app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $out, 'Wrong account'), true);

    expect($this->settlement->fresh()->status->value)->toBe('approved')
        ->and(DepositMovement::heldFils($this->au->id))->toBe(400_000)
        ->and(DepositMovement::where('type', 'refunded')->where('amount', '>', 0)->count())->toBe(1);
});

test('a cheque deposit refund needs its cheque details and issues the cheque', function () {
    $data = ['purpose' => 'deposit_refund', 'deposit_settlement_id' => $this->settlement->id, 'amount' => '100.000', 'method' => 'cheque', 'paid_on' => '2026-10-05'];
    expect(fn () => app(RecordDisbursement::class)->handle($this->finance, $data))->toThrow(ValidationException::class);

    $out = app(RecordDisbursement::class)->handle($this->finance, $data + ['cheque_no' => '77', 'bank_name' => 'BBK', 'cheque_date' => '2026-10-05']);
    expect($out->cheque_id)->not->toBeNull();
});
