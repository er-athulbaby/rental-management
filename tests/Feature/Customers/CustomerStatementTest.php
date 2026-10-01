<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RequestPaymentReversal;
use App\Billing\CustomerCredit;
use App\Billing\CustomerStatement;
use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Customers\Statement;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->customer = Customer::factory()->create();
    $pay = fn (string $on, string $amount) => app(RecordPayment::class)->handle($this->finance, $this->customer, ['received_on' => $on, 'method' => 'cash', 'amount' => $amount]);

    $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00', 'Asia/Bahrain'));
    issuedInvoice($this->customer, [['net' => '100.000']], '2026-09-10');      // before the period: opening
    $pay('2026-09-10', '100.000');

    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Asia/Bahrain'));
    $this->october = issuedInvoice($this->customer, [['net' => '400.000']], '2026-10-01');
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Bahrain'));
    $this->bounced = $pay('2026-10-03', '400.000');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(DecideApproval::class)->handle($this->management, app(RequestPaymentReversal::class)->handle($this->finance, $this->bounced, 'Recalled'), true);
    $cn = app(SaveCreditNote::class)->handle($this->finance, $this->october, null, ['reason' => 'Concession', 'lines' => [['credited_line_id' => $this->october->lines->sole()->id, 'amount' => '50.000']]]);
    app(DecideApproval::class)->handle($this->management, app(SubmitCreditNote::class)->handle($this->finance, $cn), true);
    $pay('2026-10-05', '500.000');
});

test('the receivables statement runs invoices, credit notes, payments and reversals to a balance', function () {
    $s = CustomerStatement::receivables($this->customer, '2026-10-01', '2026-10-31');

    expect($s['opening'])->toBe(0)
        ->and(array_column($s['rows'], 'kind'))->toBe(['Invoice', 'Payment', 'Payment reversal', 'Credit note', 'Payment'])
        ->and(array_column($s['rows'], 'balance'))->toBe([400_000, 0, 400_000, 350_000, -150_000])
        ->and($s['closing'])->toBe(-150_000);

    // The statement and the cached balances agree: closing = outstanding − credit.
    $outstanding = Fils::fromDecimal((string) Invoice::where('customer_id', $this->customer->id)->where('status', InvoiceStatus::Issued)->where('type', '!=', 'credit_note')->sum('balance'));
    expect($s['closing'])->toBe($outstanding - CustomerCredit::fils($this->customer->id));
});

test('the deposit statement runs per agreement unit', function () {
    $unit = Unit::factory()->create(['code' => 'A-101']);
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30'], [$unit]);
    issuedInvoice($this->customer, [['net' => '400.000', 'tax' => 'out_of_scope', 'type' => 'deposit', 'au' => $agreement->agreementUnits()->sole()]], '2026-10-01', $agreement);
    // The 150.000 credit auto-allocates to the deposit on issue.

    $deposits = CustomerStatement::deposits($this->customer, '2026-10-01', '2026-10-31');

    expect($deposits)->toHaveCount(1)
        ->and($deposits[0]['unit'])->toContain('A-101')
        ->and($deposits[0]['closing'])->toBe(150_000)
        ->and($deposits[0]['rows'][0]['kind'])->toBe('Received');
});

test('the screen and the PDF show the statement to finance.view holders only', function () {
    Livewire::withQueryParams(['from' => '2026-10-01', 'to' => '2026-10-31'])->actingAs($this->finance)->test(Statement::class, ['customer' => $this->customer])
        ->assertSee($this->october->number)->assertSee('-150.000');

    $this->actingAs($this->management)->get(route('customers.statement.pdf', ['customer' => $this->customer, 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($leasing)->get(route('customers.statement', $this->customer))->assertForbidden();
});
