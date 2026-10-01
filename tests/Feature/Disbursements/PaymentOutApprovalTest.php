<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Models\Approval;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->other = fn (array $over = []) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'other', 'payee_type' => 'owner', 'payee_id' => Owner::factory()->create()->id, 'amount' => '75.000',
        'method' => 'bank_transfer', 'reason' => 'Owner share of a shared repair', ...$over,
    ]);
});

test('a payment out without a source waits for Management, then Finance pays it', function () {
    $out = ($this->other)();
    expect($out->status->value)->toBe('pending_approval')->and($out->number)->toBeNull();

    app(DecideApproval::class)->handle($this->management, Approval::sole(), true);
    expect($out->fresh()->status->value)->toBe('approved');

    app(PayDisbursement::class)->handle($this->finance, $out->fresh(), ['method' => 'cash', 'paid_on' => '2026-10-05', 'reference' => 'Voucher 12']);

    $paid = $out->fresh();
    expect($paid->status->value)->toBe('paid')
        ->and($paid->number)->toBe('PO-2026-000001')
        ->and($paid->method->value)->toBe('cash')
        ->and($paid->paid_on->toDateString())->toBe('2026-10-05');
});

test('rejecting leaves it rejected for good; unapproved rows cannot be paid', function () {
    $out = ($this->other)();
    expect(fn () => app(PayDisbursement::class)->handle($this->finance, $out, ['method' => 'cash', 'paid_on' => '2026-10-05']))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, Approval::sole(), false, 'Not ours to pay');
    expect($out->fresh()->status->value)->toBe('rejected');
    expect(fn () => app(PayDisbursement::class)->handle($this->finance, $out->fresh(), ['method' => 'cash', 'paid_on' => '2026-10-05']))->toThrow(ValidationException::class);
});

test('the requester cannot approve their own payment out; a customer payee works too', function () {
    $dual = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance)->assignRole(RoleName::Management);
    app(RecordDisbursement::class)->handle($dual, ['purpose' => 'other', 'payee_type' => 'customer', 'payee_id' => Customer::factory()->create()->id, 'amount' => '5', 'method' => 'cash', 'reason' => 'Goodwill']);

    expect(fn () => app(DecideApproval::class)->handle($dual, Approval::sole(), true))->toThrow(ValidationException::class);
    expect(fn () => ($this->other)(['reason' => '']))->toThrow(ValidationException::class);
});
