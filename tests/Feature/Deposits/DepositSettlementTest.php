<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Deposits\CreateDepositSettlement;
use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true, 'vat_registered' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30'], [Unit::factory()->create()]);
    $this->au = $this->agreement->agreementUnits()->sole();
    // Deposit 400 paid in full; September rent 400 still unpaid.
    issuedInvoice($this->customer, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $this->au]], '2025-10-01', $this->agreement);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '400.000']);
    $this->rent = issuedInvoice($this->customer, [['net' => '400.000', 'au' => $this->au]], '2026-09-01', $this->agreement);
    $this->au->forceFill(['move_out_date' => '2026-09-30'])->save();
    $this->settlement = DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($this->agreement, [$this->au->id], $this->finance));
});

test('a draft lists each unit with the deposit held, once', function () {
    expect($this->settlement->status->value)->toBe('draft')
        ->and($this->settlement->units->sole()->held_amount)->toBe('400.000')
        ->and(DB::transaction(fn () => app(CreateDepositSettlement::class)->handle($this->agreement, [$this->au->id], $this->finance)))->toBeNull();
});

test('approval invoices the non-rent deductions, applies the deposit to them and the named rent, and leaves the refund', function () {
    $line = $this->rent->lines->sole();
    app(SaveSettlementDeductions::class)->handle($this->finance, $this->settlement, [
        ['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'September rent', 'amount' => '250.000', 'invoice_line_id' => $line->id],
        ['agreement_unit_id' => $this->au->id, 'type' => 'cleaning', 'description' => 'Deep clean', 'amount' => '60.000'],
    ]);
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement->fresh()), true);

    $s = $this->settlement->fresh(['units', 'deductionsInvoice', 'payment']);
    expect($s->status->value)->toBe('approved')
        ->and($s->number)->toBe('DS-2026-000001')
        ->and($s->deductionsInvoice->status->value)->toBe('issued')
        ->and($s->deductionsInvoice->total)->toBe('60.000')
        ->and($s->deductionsInvoice->due_date->toDateString())->toBe('2026-09-30')
        ->and($s->deductionsInvoice->balance)->toBe('0.000')
        ->and($s->payment->method->value)->toBe('deposit_applied')
        ->and($s->payment->amount)->toBe('310.000')
        ->and($line->fresh()->allocated)->toBe('250.000')
        ->and($s->units->sole()->applied_amount)->toBe('310.000')
        ->and($s->units->sole()->refund_amount)->toBe('90.000')
        ->and($s->refundFils())->toBe(90_000)
        ->and(DepositMovement::heldFils($this->au->id))->toBe(90_000)
        ->and(DepositMovement::where('type', 'applied')->sole()->amount)->toBe('-310.000')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(0)
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('deductions beyond the deposit are capped by the held amount and stay owed', function () {
    app(SaveSettlementDeductions::class)->handle($this->finance, $this->settlement, [
        ['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'September rent', 'amount' => '400.000', 'invoice_line_id' => $this->rent->lines->sole()->id],
        ['agreement_unit_id' => $this->au->id, 'type' => 'damage', 'description' => 'Broken door', 'amount' => '150.000'],
    ]);
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement->fresh()), true);

    $s = $this->settlement->fresh(['units', 'deductionsInvoice']);
    expect($s->units->sole()->applied_amount)->toBe('400.000')
        ->and($s->units->sole()->refund_amount)->toBe('0.000')
        ->and($s->status->value)->toBe('completed') // nothing to refund
        ->and($this->rent->fresh()->balance)->toBe('0.000')
        ->and($s->deductionsInvoice->balance)->toBe('150.000')
        ->and(DepositMovement::heldFils($this->au->id))->toBe(0);
});

test('no deductions: the whole deposit is the refund and no payment is made', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement->fresh()), true);

    $s = $this->settlement->fresh(['units']);
    expect($s->status->value)->toBe('approved')
        ->and($s->payment_id)->toBeNull()
        ->and($s->deductions_invoice_id)->toBeNull()
        ->and($s->units->sole()->refund_amount)->toBe('400.000');
});

test('deductions are validated; only invoices.manage edits; rejecting returns the draft', function () {
    $other = issuedInvoice(Customer::factory()->create(), [['net' => '1.000']]);
    foreach ([
        [['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'x', 'amount' => '1', 'invoice_line_id' => $other->lines->sole()->id]],
        [['agreement_unit_id' => $this->au->id, 'type' => 'unpaid_rent', 'description' => 'x', 'amount' => '400.001', 'invoice_line_id' => $this->rent->lines->sole()->id]],
        [['agreement_unit_id' => $this->au->id, 'type' => 'damage', 'description' => '', 'amount' => '1']],
        [['agreement_unit_id' => 999999, 'type' => 'damage', 'description' => 'x', 'amount' => '1']],
    ] as $bad) {
        expect(fn () => app(SaveSettlementDeductions::class)->handle($this->finance, $this->settlement, $bad))->toThrow(ValidationException::class);
    }

    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    expect(fn () => app(SaveSettlementDeductions::class)->handle($pm, $this->settlement, []))->toThrow(AuthorizationException::class);

    $approval = app(SubmitDepositSettlement::class)->handle($this->finance, $this->settlement);
    app(DecideApproval::class)->handle($this->management, $approval, false, 'Add the cleaning');
    expect($this->settlement->fresh()->status->value)->toBe('draft');
});
