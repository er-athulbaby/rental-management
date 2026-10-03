<?php

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Cheques\ClearCheque;
use App\Actions\Cheques\DepositCheques;
use App\Actions\Cheques\RecordCheques;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Billing\CustomerCredit;
use App\Billing\CustomerStatement;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

// Spec §15 M3 exit: one full month's rent cycle reconciles to the fil. Real flows only: no forceFill shortcuts.

test('October 2026 reconciles to the fil', function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true, 'invoice_lead_days' => 0]);
    app(EnsureDefaultContractTemplate::class)();
    app(EnsureNumberSequences::class)(2026);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00', 'Asia/Bahrain'));

    $leasing = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Leasing);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    [$b1, $b2] = Building::factory()->count(2)->create();
    $leasing->buildings()->attach([$b1->id, $b2->id]);
    $shop = Unit::factory()->for($b1)->create(['list_rent' => '550.000']);
    $office = Unit::factory()->for($b2)->create(['list_rent' => '800.000']);
    $flat = Unit::factory()->for($b1)->create(['list_rent' => '300.000']);
    activeOwnerContract(['building_id' => $b2->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2028-12-31'], [$office]);

    $corporate = Customer::factory()->create();
    $family = Customer::factory()->create();
    $agree = function (Customer $c, array $units, string $from, ?int $billingDay = null) use ($leasing, $management) {
        $draft = app(SaveAgreement::class)->handle($leasing, null, [
            'customer_id' => $c->id, 'start_date' => $from, 'end_date' => CarbonImmutable::parse($from)->addYear()->subDay()->toDateString(), 'frequency' => 'monthly',
            'billing_day' => $billingDay,
            'units' => array_map(fn (array $u) => ['unit_id' => $u[0]->id, 'deposit_amount' => $u[2], 'charges' => [['type' => 'rent', 'monthly_amount' => $u[1], 'tax_category' => $u[3]]]], $units),
        ]);
        app(DecideApproval::class)->handle($management, app(SubmitAgreement::class)->handle($leasing, $draft), true);

        return $draft->fresh();
    };
    // Multi-unit across two buildings, commercial (standard-rated); and a residential flat starting mid-month (exempt, prorated stub).
    $a1 = $agree($corporate, [[$shop, '500.000', '500.000', 'standard'], [$office, '800.000', '800.000', 'standard']], '2026-10-01');
    $a2 = $agree($family, [[$flat, '300.000', '300.000', 'exempt']], '2026-10-16', billingDay: 1); // stub 16–31 October

    // 1 October, 01:00: the month's rent is issued.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 01:00', 'Asia/Bahrain'));
    $this->artisan('rms:invoices:issue')->assertExitCode(0);

    // The deposit invoices were issued at activation (25 September), due on each start date.
    // Corporate: October's cheque clears; a transfer pays both deposits. Family: cash pays the stub (lower id) and part of the deposit.
    $octRent = Invoice::where('agreement_id', $a1->id)->where('type', 'rent')->where('status', 'issued')->sole();
    fixtureBanks();
    $cheque = app(RecordCheques::class)->handle($finance, $corporate, $a1, [['cheque_no' => '7001', 'bank_name' => 'NBB', 'cheque_date' => '2026-10-01', 'amount' => $octRent->total, 'invoice_id' => $octRent->id]])->sole();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Bahrain'));
    app(DepositCheques::class)->handle($finance, [$cheque->id], '2026-10-02');
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Bahrain'));
    app(ClearCheque::class)->handle($finance, $cheque->fresh(), '2026-10-04');
    app(RecordPayment::class)->handle($finance, $corporate, ['received_on' => '2026-10-04', 'method' => 'bank_transfer', 'amount' => '1300.000']); // deposits

    $this->travelTo(CarbonImmutable::parse('2026-10-16 01:00', 'Asia/Bahrain'));
    $this->artisan('rms:invoices:issue')->assertExitCode(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 11:00', 'Asia/Bahrain'));
    app(RecordPayment::class)->handle($finance, $family, ['received_on' => '2026-10-16', 'method' => 'cash', 'amount' => '350.000']);

    // A concession: credit 55.000 (50.000 + VAT) on the shop's October line, already paid → de-allocated back to credit.
    $shopLine = $octRent->lines()->where('unit_id', $shop->id)->sole();
    $cn = app(SaveCreditNote::class)->handle($finance, $octRent, null, ['reason' => 'Opening concession', 'lines' => [['credited_line_id' => $shopLine->id, 'amount' => '55.000']]]);
    app(DecideApproval::class)->handle($management, app(SubmitCreditNote::class)->handle($finance, $cn), true);

    // ---- Reconcile ----
    $this->travelTo(CarbonImmutable::parse('2026-10-31 23:00', 'Asia/Bahrain'));
    expect(app(IntegrityCheck::class)->run())->toBe([]);

    foreach ([$corporate, $family] as $customer) {
        $issued = Invoice::where('customer_id', $customer->id)->where('status', 'issued');
        $billed = Fils::fromDecimal((string) (clone $issued)->where('type', '!=', 'credit_note')->sum('total'));
        $credited = Fils::fromDecimal((string) (clone $issued)->where('type', 'credit_note')->sum('total'));
        $paid = Fils::fromDecimal((string) $customer->payments()->where('status', 'confirmed')->sum('amount'));
        $outstanding = Fils::fromDecimal((string) (clone $issued)->where('type', '!=', 'credit_note')->sum('balance'));
        $credit = CustomerCredit::fils($customer->id);

        // Every fil billed is either still owed, credited, or paid; every fil paid is allocated or held as credit.
        expect($billed - $credited - $paid)->toBe($outstanding - $credit, "customer {$customer->id}")
            ->and(CustomerStatement::receivables($customer, '2026-10-01', '2026-10-31')['closing'])->toBe($outstanding - $credit)
            ->and($credit)->toBeGreaterThanOrEqual(0);
    }

    // Exact expectations for the month.
    $flatOct = Invoice::where('agreement_id', $a2->id)->where('type', 'rent')->where('status', 'issued')->sole();
    expect($octRent->fresh()->total)->toBe('1430.000')           // (500 + 800) × 1.10
        ->and($flatOct->total)->toBe('157.808')                  // 300 × 12 / 365 × 16 days (actual_365, the default basis)
        // 350 cash meets two invoices due 16 October; the stub has the lower id (activation builds the schedule before
        // the deposit invoice), so it is paid in full and the deposit takes the remaining 192.192.
        ->and($flatOct->fresh()->balance)->toBe('0.000')
        ->and(Invoice::where('agreement_id', $a2->id)->where('type', 'deposit')->sole()->balance)->toBe('107.808')
        ->and($octRent->fresh()->balance)->toBe('0.000')         // 1375 paid + 55 credited
        ->and(CustomerCredit::fils($corporate->id))->toBe(55_000) // the de-allocated 55 is credit until November is issued
        ->and(Fils::fromDecimal((string) DepositMovement::sum('amount')))->toBe(1_300_000 + 192_192) // held as deposit: what was allocated to deposit lines
        ->and($shopLine->fresh()->credited)->toBe('55.000')
        ->and(Invoice::where('type', 'credit_note')->sole()->tax_total)->toBe('5.000');

    // §14 flow 4: tax carried by allocations on fully paid lines equals the lines' tax.
    foreach (Invoice::where('status', 'issued')->where('type', '!=', 'credit_note')->with('lines')->get()->flatMap->lines as $line) {
        if ($line->balanceFils() === 0 && $line->credited === '0.000') {
            expect(Fils::fromDecimal((string) PaymentAllocation::where('invoice_line_id', $line->id)->sum('tax_amount')))->toBe(Fils::fromDecimal($line->tax_amount));
        }
    }
});
