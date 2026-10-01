<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Enums\RoleName;
use App\Livewire\Invoices\CreditNoteForm;
use App\Livewire\Invoices\Show;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt (Task 9)
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $this->invoice = issuedInvoice($this->customer, [['net' => '100.000', 'tax' => 'standard'], ['net' => '50.000']]); // 110 + 50
    [$this->taxed, $this->plain] = $this->invoice->lines->sortBy('id')->values()->all();
    $this->approveCn = function (array $lines, string $reason = 'Rent concession') {
        $cn = app(SaveCreditNote::class)->handle($this->finance, $this->invoice, null, ['reason' => $reason, 'lines' => $lines]);
        $approval = app(SubmitCreditNote::class)->handle($this->finance, $cn);
        app(DecideApproval::class)->handle($this->management, $approval, true);

        return $cn->fresh(['lines']);
    };
});

test('a credit note on an unpaid line reduces the balance only through credited', function () {
    $cn = ($this->approveCn)([['credited_line_id' => $this->taxed->id, 'amount' => '55.000']]);

    expect($cn->status->value)->toBe('issued')
        ->and($cn->number)->toBe('CN-2026-000001')
        ->and($cn->type->value)->toBe('credit_note')
        ->and($cn->related_invoice_id)->toBe($this->invoice->id)
        ->and($cn->total)->toBe('55.000')
        ->and($cn->tax_total)->toBe('5.000')
        ->and($cn->balance)->toBe('0.000')
        ->and($cn->lines->sole()->tax_rate)->toBe('10.00')
        ->and($this->taxed->fresh()->credited)->toBe('55.000')
        ->and($this->invoice->fresh()->credited)->toBe('55.000')
        ->and($this->invoice->fresh()->balance)->toBe('105.000');
});

test('crediting the rest of a line takes exactly its remaining tax', function () {
    ($this->approveCn)([['credited_line_id' => $this->taxed->id, 'amount' => '33.333']]);
    $second = ($this->approveCn)([['credited_line_id' => $this->taxed->id, 'amount' => '76.667']]);

    expect(Fils::fromDecimal(Invoice::where('type', 'credit_note')->sum('tax_total')))->toBe(10_000)
        ->and($second->lines->sole()->tax_amount)->toBe('6.970');
});

test('§14 flow 9: a credit note on a partly paid line de-allocates newest first and returns it as credit', function () {
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-04', 'method' => 'cash', 'amount' => '60.000']);
    app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '64.000']);
    // Each payment split across both lines; the taxed line now has balance b, and we credit it in full.
    $line = $this->taxed->fresh();
    $balance = $line->balanceFils();

    ($this->approveCn)([['credited_line_id' => $line->id, 'amount' => '110.000']]);

    $reversed = PaymentAllocation::where('amount', '<', 0)->orderBy('id')->get();
    expect(Fils::fromDecimal((string) $reversed->sum('amount')))->toBe(-(110_000 - $balance))
        ->and($reversed->first()->reverses->payment->received_on->toDateString())->toBe('2026-10-05') // newest first
        ->and($line->fresh()->allocated)->toBe('0.000')
        ->and($line->fresh()->credited)->toBe('110.000')
        ->and(CustomerCredit::fils($this->customer->id))->toBe(110_000 - $balance);
});

test('credit notes never appear in balances or allocation defaults', function () {
    ($this->approveCn)([['credited_line_id' => $this->plain->id, 'amount' => '50.000']]);

    $payment = app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => '500.000']);

    expect(PaymentAllocation::where('payment_id', $payment->id)->get()->every(fn ($a) => $a->line->invoice_id === $this->invoice->id))->toBeTrue()
        ->and(Invoice::where('type', 'credit_note')->sole()->allocated)->toBe('0.000')
        ->and(Invoice::where('type', 'credit_note')->sole()->displayLabel())->toBe('Credit note');
});

test('over-crediting and foreign lines are refused; rejecting returns the draft', function () {
    expect(fn () => app(SaveCreditNote::class)->handle($this->finance, $this->invoice, null, ['reason' => 'x', 'lines' => [['credited_line_id' => $this->plain->id, 'amount' => '50.001']]]))
        ->toThrow(ValidationException::class);
    $other = issuedInvoice($this->customer, [['net' => '10.000']]);
    expect(fn () => app(SaveCreditNote::class)->handle($this->finance, $this->invoice, null, ['reason' => 'x', 'lines' => [['credited_line_id' => $other->lines->sole()->id, 'amount' => '1']]]))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveCreditNote::class)->handle($this->finance, $this->invoice, null, ['reason' => '', 'lines' => [['credited_line_id' => $this->plain->id, 'amount' => '1']]]))
        ->toThrow(ValidationException::class);

    $cn = app(SaveCreditNote::class)->handle($this->finance, $this->invoice, null, ['reason' => 'x', 'lines' => [['credited_line_id' => $this->plain->id, 'amount' => '10']]]);
    $approval = app(SubmitCreditNote::class)->handle($this->finance, $cn);
    expect($cn->fresh()->status->value)->toBe('pending_approval');

    app(DecideApproval::class)->handle($this->management, $approval, false, 'Wrong amount');
    expect($cn->fresh()->status->value)->toBe('draft')->and($this->plain->fresh()->credited)->toBe('0.000');
});

test('approval refuses a credit note that no longer fits its line', function () {
    $first = app(SaveCreditNote::class)->handle($this->finance, $this->invoice, null, ['reason' => 'x', 'lines' => [['credited_line_id' => $this->plain->id, 'amount' => '40']]]);
    $second = app(SaveCreditNote::class)->handle($this->finance, $this->invoice, null, ['reason' => 'y', 'lines' => [['credited_line_id' => $this->plain->id, 'amount' => '40']]]);
    $a = app(SubmitCreditNote::class)->handle($this->finance, $first);
    $b = app(SubmitCreditNote::class)->handle($this->finance, $second);

    app(DecideApproval::class)->handle($this->management, $a, true);
    expect(fn () => app(DecideApproval::class)->handle($this->management, $b, true))->toThrow(ValidationException::class);
});

test('only invoices.manage drafts; the screens work', function () {
    expect(fn () => app(SaveCreditNote::class)->handle($this->management, $this->invoice, null, ['reason' => 'x', 'lines' => []]))->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->finance)->test(CreditNoteForm::class, ['invoice' => $this->invoice])
        ->set('reason', 'Goodwill')
        ->set("amounts.{$this->plain->id}", '20')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $cn = Invoice::where('type', 'credit_note')->sole();
    expect($cn->status->value)->toBe('pending_approval');
    Livewire::actingAs($this->finance)->test(Show::class, ['invoice' => $this->invoice])->assertSee(__('Credit note'));
});
