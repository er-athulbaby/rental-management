<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $this->invoice = issuedInvoice($this->customer, [['net' => '1.000', 'tax' => 'standard']]); // 1.100 incl. 0.100 tax
    $this->line = $this->invoice->lines->sole();
    $this->pay = fn (string $amount) => app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => '2026-10-05', 'method' => 'cash', 'amount' => $amount]);
    $this->credit = function (string $amount) {
        $cn = app(SaveCreditNote::class)->handle($this->finance, $this->invoice->fresh(), null, ['reason' => 'x', 'lines' => [['credited_line_id' => $this->line->id, 'amount' => $amount]]]);
        app(DecideApproval::class)->handle($this->management, app(SubmitCreditNote::class)->handle($this->finance, $cn), true);

        return $cn->fresh();
    };
    // Σ tax carried by allocations and issued credit notes on the line.
    $this->taxCarried = fn () => Fils::fromDecimal((string) PaymentAllocation::where('invoice_line_id', $this->line->id)->sum('tax_amount'))
        + Fils::fromDecimal((string) Invoice::where('type', 'credit_note')->where('status', 'issued')->sum('tax_total'));
});

test('credit first, then the rest paid: the line carries exactly its tax', function () {
    ($this->credit)('0.333');      // 30 fils of tax (cumulative split)
    ($this->pay)('0.767');         // takes the remaining 70, not 69

    expect(($this->taxCarried)())->toBe(100)->and(app(IntegrityCheck::class)->run())->toBe([]);
});

test('paid first, then the rest credited: the credit takes the remaining tax', function () {
    ($this->pay)('0.767');         // 69 fils
    $cn = ($this->credit)('0.333'); // settles the line: 31, not 30

    expect($cn->tax_total)->toBe('0.031')->and(($this->taxCarried)())->toBe(100);
});

test('a credit beyond the balance de-allocates and still adds up exactly', function () {
    ($this->pay)('0.900');         // 81 fils
    ($this->credit)('0.500');      // de-allocates 0.300 (−27 fils), credit takes 46

    expect(Fils::fromDecimal((string) PaymentAllocation::where('invoice_line_id', $this->line->id)->sum('tax_amount')))->toBe(54)
        ->and(($this->taxCarried)())->toBe(100);
});

test('a reversal unwinds the allocations\' tax to zero', function () {
    $payment = ($this->pay)('0.500');
    ($this->pay)('0.600');
    app(DecideApproval::class)->handle($this->management, app(RequestPaymentReversal::class)->handle($this->finance, $payment, 'x'), true);

    $live = PaymentAllocation::where('invoice_line_id', $this->line->id);
    expect(Fils::fromDecimal((string) $live->sum('amount')))->toBe(600)
        ->and(Fils::fromDecimal((string) $live->sum('tax_amount')))->toBe(54); // ⌊600 × 100 / 1100⌋
});
