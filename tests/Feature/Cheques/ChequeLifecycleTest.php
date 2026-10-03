<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\GenerateRentSchedule;
use App\Actions\Cheques\BounceCheque;
use App\Actions\Cheques\ClearCheque;
use App\Actions\Cheques\DepositCheques;
use App\Actions\Cheques\EndCheque;
use App\Actions\Cheques\RecordCheques;
use App\Actions\Cheques\ReplaceCheque;
use App\Actions\EnsureNumberSequences;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt (Task 9)
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $this->agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30'], [Unit::factory()->create()]);
    DB::transaction(fn () => app(GenerateRentSchedule::class)->handle($this->agreement, $this->finance)); // 12 scheduled, 400 each
    $this->invoices = Invoice::where('agreement_id', $this->agreement->id)->orderBy('due_date')->get();
    $row = fn (int $i) => ['cheque_no' => (string) (100 + $i), 'bank_name' => 'NBB', 'cheque_date' => $this->invoices[$i]->due_date->toDateString(), 'amount' => '400.000', 'invoice_id' => $this->invoices[$i]->id];
    $this->cheques = app(RecordCheques::class)->handle($this->finance, $this->customer, $this->agreement, [$row(0), $row(1), $row(2)]);
});

test('post-dated cheques are entered held, each matched to its scheduled invoice; deposit in a batch', function () {
    expect($this->cheques)->toHaveCount(3)
        ->and($this->cheques->every(fn (Cheque $c) => $c->status->value === 'held' && $c->direction->value === 'received'))->toBeTrue()
        ->and($this->cheques[1]->invoice_id)->toBe($this->invoices[1]->id);

    expect(app(DepositCheques::class)->handle($this->finance, $this->cheques->pluck('id')->all(), '2026-10-05'))->toBe(3);
    expect(Cheque::where('status', 'deposited')->count())->toBe(3)->and(Payment::count())->toBe(0);
});

test('§14 flow 5: clearing creates the payment, issuing a still-scheduled target first', function () {
    app(DepositCheques::class)->handle($this->finance, [$this->cheques[1]->id], '2026-10-05');
    $target = $this->invoices[1]; // November, still scheduled

    $payment = app(ClearCheque::class)->handle($this->finance, $this->cheques[1]->fresh(), '2026-10-05');

    expect($payment->method->value)->toBe('cheque')
        ->and($payment->received_on->toDateString())->toBe('2026-10-05')
        ->and($payment->cheque_id)->toBe($this->cheques[1]->id)
        ->and($target->fresh()->status->value)->toBe('issued')
        ->and($target->fresh()->balance)->toBe('0.000')
        ->and($this->cheques[1]->fresh()->status->value)->toBe('cleared')
        ->and($this->cheques[1]->fresh()->payment_id)->toBe($payment->id);
});

test('a bounce before clearing makes no payment; the replacement is linked; a returned cheque ends', function () {
    app(DepositCheques::class)->handle($this->finance, [$this->cheques[0]->id], '2026-10-05');
    expect(app(BounceCheque::class)->handle($this->finance, $this->cheques[0]->fresh(), '2026-10-05', 'Insufficient funds'))->toBeNull();

    $bounced = $this->cheques[0]->fresh();
    expect($bounced->status->value)->toBe('bounced')->and($bounced->bounce_reason)->toBe('Insufficient funds')->and(Payment::count())->toBe(0);

    $new = app(ReplaceCheque::class)->handle($this->finance, $bounced, ['cheque_no' => '900', 'bank_name' => 'BBK', 'cheque_date' => '2026-10-10', 'amount' => '400.000']);
    expect($bounced->fresh()->status->value)->toBe('replaced')
        ->and($bounced->fresh()->replaced_by_cheque_id)->toBe($new->id)
        ->and($new->status->value)->toBe('held')
        ->and($new->invoice_id)->toBe($bounced->invoice_id);

    app(EndCheque::class)->handle($this->finance, $this->cheques[2], 'returned', '2026-10-05');
    expect($this->cheques[2]->fresh()->status->value)->toBe('returned');
});

test('§14 flow 5: a cheque returned after clearing goes through a payment reversal and then bounces', function () {
    app(DepositCheques::class)->handle($this->finance, [$this->cheques[0]->id], '2026-10-05');
    $payment = app(ClearCheque::class)->handle($this->finance, $this->cheques[0]->fresh(), '2026-10-05');

    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Bahrain'));
    $approval = app(BounceCheque::class)->handle($this->finance, $this->cheques[0]->fresh(), '2026-10-06', 'Returned by the bank');
    expect($this->cheques[0]->fresh()->status->value)->toBe('cleared'); // until approved

    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'Asia/Bahrain'));
    app(DecideApproval::class)->handle($this->management, $approval, true);

    $cheque = $this->cheques[0]->fresh();
    expect($payment->fresh()->status->value)->toBe('reversed')
        ->and($cheque->status->value)->toBe('bounced')
        ->and($cheque->bounced_on->toDateString())->toBe('2026-10-06') // the bank's date, not the approval's
        ->and($cheque->bounce_reason)->toBe('Returned by the bank')
        ->and($this->invoices[0]->fresh()->balance)->toBe('400.000')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(0);
});

test('the lifecycle refuses wrong steps and wrong roles', function () {
    expect(fn () => app(ClearCheque::class)->handle($this->finance, $this->cheques[0], '2026-10-05'))->toThrow(ValidationException::class); // not deposited
    expect(fn () => app(EndCheque::class)->handle($this->finance, $this->cheques[0], 'replaced'))->toThrow(ValidationException::class);
    expect(fn () => app(DepositCheques::class)->handle($this->finance, [$this->cheques[0]->id], '2026-10-06'))->toThrow(ValidationException::class); // future
    expect(fn () => app(RecordCheques::class)->handle($this->finance, $this->customer, $this->agreement, [['cheque_no' => '', 'bank_name' => 'x', 'cheque_date' => '2026-10-01', 'amount' => '1']]))
        ->toThrow(ValidationException::class);
    $other = issuedInvoice(Customer::factory()->create(), [['net' => '1.000']]);
    expect(fn () => app(RecordCheques::class)->handle($this->finance, $this->customer, $this->agreement, [['cheque_no' => '1', 'bank_name' => 'x', 'cheque_date' => '2026-10-01', 'amount' => '1', 'invoice_id' => $other->id]]))
        ->toThrow(ValidationException::class);

    $management = $this->management;
    expect(fn () => app(DepositCheques::class)->handle($management, [$this->cheques[0]->id], '2026-10-05'))->toThrow(AuthorizationException::class);

    app(EndCheque::class)->handle($this->finance, $this->cheques[0], 'cancelled');
    expect($this->cheques[0]->fresh()->status->value)->toBe('cancelled');
});
