<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Actions\Agreements\RecordMoveOut;
use App\Actions\Agreements\RenewAgreement;
use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    app(EnsureDefaultContractTemplate::class)();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $building = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($building->id);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    [$this->u1, $this->u2] = Unit::factory()->for($building)->count(2)->create()->all();
    $this->old = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', 'created_by' => $this->leasing->id], [$this->u1, $this->u2]); // deposits 400 each
    $this->oldAu = fn (Unit $u) => $this->old->agreementUnits()->where('unit_id', $u->id)->sole();
    $this->depositPaid = function (string $paid) {
        issuedInvoice($this->customer, [
            ['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => ($this->oldAu)($this->u1)],
            ['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => ($this->oldAu)($this->u2)],
        ], '2025-10-01', $this->old);
        app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => $paid]);
    };
    app(ExpireAgreements::class)(); // the old agreement expired on 30 September
    $this->renew = function (string $deposit) {
        $draft = app(RenewAgreement::class)->handle($this->leasing, $this->old->fresh(), [$this->u1->id], '2027-09-30');
        $data = ['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'frequency' => 'monthly',
            'units' => [['unit_id' => $this->u1->id, 'deposit_amount' => $deposit, 'charges' => [['type' => 'rent', 'monthly_amount' => '420.000', 'tax_category' => 'exempt']]]]];
        app(SaveAgreement::class)->handle($this->leasing, $draft, $data); // rent and deposit edited on the draft
        app(DecideApproval::class)->handle($this->management, app(SubmitAgreement::class)->handle($this->leasing, $draft->fresh()), true);

        return $draft->fresh();
    };
});

test('§14 flow 10: a renewal after expiry passes the overlap check and carries the deposit', function () {
    ($this->depositPaid)('800.000');
    expect($this->old->fresh()->status->value)->toBe('expired');

    $renewal = ($this->renew)('500.000');
    $newAu = $renewal->agreementUnits()->sole();

    expect($renewal->status->value)->toBe('active')
        ->and($renewal->previous_agreement_id)->toBe($this->old->id)
        ->and($renewal->start_date->toDateString())->toBe('2026-10-01')
        ->and(DepositMovement::heldFils(($this->oldAu)($this->u1)->id))->toBe(0)
        ->and(DepositMovement::heldFils($newAu->id))->toBe(400_000)
        ->and(DepositMovement::where('type', 'transfer_out')->sole()->amount)->toBe('-400.000')
        ->and(Invoice::where('agreement_id', $renewal->id)->where('type', 'deposit')->sole()->total)->toBe('100.000'); // 500 − 400 carried

    // Unit 2 was not carried: it needs its own move-out, which drafts its settlement and lets the old agreement renew.
    expect($this->old->fresh()->status->value)->toBe('expired');
    app(RecordMoveOut::class)->handle($this->leasing, $this->old->fresh(), ($this->oldAu)($this->u2), '2026-10-03', null, null);
    expect($this->old->fresh()->status->value)->toBe('renewed')
        ->and(DepositSettlement::sole()->units->sole()->agreement_unit_id)->toBe(($this->oldAu)($this->u2)->id)
        ->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('a smaller new deposit leaves the rest on the old unit for a settlement', function () {
    ($this->depositPaid)('800.000');

    ($this->renew)('300.000');

    expect(DepositMovement::heldFils(($this->oldAu)($this->u1)->id))->toBe(100_000)
        ->and(DepositSettlement::sole()->units->sole()->agreement_unit_id)->toBe(($this->oldAu)($this->u1)->id)
        ->and(Invoice::where('type', 'deposit')->where('agreement_id', '!=', $this->old->id)->count())->toBe(0); // fully covered
});

test('an unpaid old deposit balance is credited and only what was paid carries over', function () {
    ($this->depositPaid)('650.000'); // 400 on unit 1 is split by balance: 325 each

    $renewal = ($this->renew)('400.000');

    $cn = Invoice::where('type', 'credit_note')->sole();
    expect($cn->total)->toBe('75.000')                 // unit 1's unpaid 75 on the old deposit line
        ->and(DepositMovement::heldFils($renewal->agreementUnits()->sole()->id))->toBe(325_000)
        ->and(Invoice::where('agreement_id', $renewal->id)->where('type', 'deposit')->sole()->total)->toBe('75.000');
});

test('renewing needs an active or expired agreement, carried units of it, and no other renewal', function () {
    expect(fn () => app(RenewAgreement::class)->handle($this->leasing, $this->old, [Unit::factory()->create()->id], '2027-09-30'))->toThrow(ValidationException::class);
    app(RenewAgreement::class)->handle($this->leasing, $this->old, [$this->u1->id], '2027-09-30');
    expect(fn () => app(RenewAgreement::class)->handle($this->leasing, $this->old, [$this->u2->id], '2027-09-30'))->toThrow(ValidationException::class);
});

test('a unit that has moved out cannot be renewed', function () {
    app(RecordMoveOut::class)->handle($this->leasing, $this->old->fresh(), ($this->oldAu)($this->u1), '2026-09-20', null, null);

    expect(fn () => app(RenewAgreement::class)->handle($this->leasing, $this->old->fresh(), [$this->u1->id], '2027-09-30'))->toThrow(ValidationException::class);
});

test('a carried unit moving out before approval stops the transfer', function () {
    ($this->depositPaid)('800.000');
    $draft = app(RenewAgreement::class)->handle($this->leasing, $this->old->fresh(), [$this->u1->id], '2027-09-30');
    app(RecordMoveOut::class)->handle($this->leasing, $this->old->fresh(), ($this->oldAu)($this->u1), '2026-09-25', null, null);
    $approval = app(SubmitAgreement::class)->handle($this->leasing, $draft->fresh()); // moved out while the renewal was a draft

    expect(fn () => app(DecideApproval::class)->handle($this->management, $approval, true))->toThrow(ValidationException::class)
        ->and(DepositMovement::whereIn('type', ['transfer_out', 'transfer_in'])->count())->toBe(0)
        ->and($draft->fresh()->status->value)->toBe('pending_approval');
});

test('a renewal drafted while the old agreement closes cannot activate', function () {
    ($this->depositPaid)('800.000');
    $draft = app(RenewAgreement::class)->handle($this->leasing, $this->old->fresh(), [$this->u1->id], '2027-09-30');
    app(RecordMoveOut::class)->handle($this->leasing, $this->old->fresh(), null, '2026-09-25', null, null);
    $approval = app(SubmitAgreement::class)->handle($this->leasing, $draft->fresh()); // closed while the renewal was a draft
    expect($this->old->fresh()->status->value)->toBe('closed');

    expect(fn () => app(DecideApproval::class)->handle($this->management, $approval, true))->toThrow(ValidationException::class)
        ->and($draft->fresh()->status->value)->toBe('pending_approval');
});

test('a unit carried into a pending or active renewal gets no move-out of its own', function () {
    ($this->depositPaid)('800.000');
    $draft = app(RenewAgreement::class)->handle($this->leasing, $this->old->fresh(), [$this->u1->id], '2027-09-30');
    $approval = app(SubmitAgreement::class)->handle($this->leasing, $draft->fresh());
    $moveOut = fn (?Unit $u) => app(RecordMoveOut::class)->handle($this->leasing, $this->old->fresh(), $u ? ($this->oldAu)($u) : null, '2026-10-03', null, null);

    foreach (['pending', 'active'] as $stage) {
        if ($stage === 'active') {
            app(DecideApproval::class)->handle($this->management, $approval, true);
        }
        foreach ([$this->u1, null] as $target) {
            try {
                $moveOut($target);
                $this->fail("A move-out on a carried unit was recorded ({$stage}).");
            } catch (ValidationException $e) {
                expect($e->errors())->toHaveKey('move_out_date');
            }
        }
        expect(($this->oldAu)($this->u1)->move_out_date)->toBeNull();
    }

    $moveOut($this->u2); // the uncarried unit still moves out
    expect(($this->oldAu)($this->u2)->move_out_date?->toDateString())->toBe('2026-10-03');
});
