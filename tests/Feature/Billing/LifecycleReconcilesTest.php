<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Actions\Agreements\RecordMoveOut;
use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SaveAmendment;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\Agreements\SubmitAmendment;
use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\IssueInvoice;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\Deposits\SaveSettlementDeductions;
use App\Actions\Deposits\SubmitDepositSettlement;
use App\Actions\Disbursements\RecordDisbursement;
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
use App\Models\DepositSettlement;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

// Spec §14 flow 8 with real Actions only: a two-unit agreement, one unit released mid-period after "Issue now",
// its move-out, settlement with deductions, the refund, and the books still reconcile to the fil.

test('a release, a move-out, a settlement and a refund keep the books exact', function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true, 'invoice_lead_days' => 0, 'vat_registered' => false]);
    app(EnsureDefaultContractTemplate::class)();
    app(EnsureNumberSequences::class)(2026);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00', 'Asia/Bahrain'));

    $building = Building::factory()->create();
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($building->id);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    [$a, $b] = Unit::factory()->for($building)->count(2)->create()->all();
    $customer = Customer::factory()->create();

    $draft = app(SaveAgreement::class)->handle($leasing, null, ['customer_id' => $customer->id, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30', 'frequency' => 'monthly',
        'units' => [
            ['unit_id' => $a->id, 'deposit_amount' => '400.000', 'charges' => [['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'exempt']]],
            ['unit_id' => $b->id, 'deposit_amount' => '300.000', 'charges' => [['type' => 'rent', 'monthly_amount' => '300.000', 'tax_category' => 'exempt']]],
        ]]);
    app(DecideApproval::class)->handle($management, app(SubmitAgreement::class)->handle($leasing, $draft), true);
    $agreement = $draft->fresh();

    // October issued at 01:00; November issued early ("Issue now"); the customer pays deposits and both months.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 01:00', 'Asia/Bahrain'));
    $this->artisan('rms:invoices:issue')->assertExitCode(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Bahrain'));
    app(IssueInvoice::class)->handle(Invoice::where('agreement_id', $agreement->id)->where('period_start', '2026-11-01')->sole(), $finance);
    app(RecordPayment::class)->handle($finance, $customer, ['received_on' => '2026-10-02', 'method' => 'bank_transfer', 'amount' => '2100.000']); // 700 deposits + 2 × 700 rent

    // Unit B released from 15 November.
    $auB = $agreement->agreementUnits()->where('unit_id', $b->id)->sole();
    $amend = app(SaveAmendment::class)->handle($leasing, $agreement, null, ['type' => 'release_unit', 'agreement_unit_id' => $auB->id, 'effective_date' => '2026-11-15', 'reason' => 'Downsizing']);
    app(DecideApproval::class)->handle($management, app(SubmitAmendment::class)->handle($leasing, $amend), true);

    $november = Invoice::where('agreement_id', $agreement->id)->where('type', 'rent')->where('period_start', '2026-11-01')->where('status', 'issued')->sole();
    expect(Invoice::where('type', 'credit_note')->count())->toBe(1)
        ->and($november->fresh()->credited)->toBe('152.055')           // B: 300 − 15 days (147.945)
        ->and(CustomerCredit::fils($customer->id))->toBe(152_055)       // paid, so it came back as credit
        ->and(Invoice::where('agreement_id', $agreement->id)->where('period_start', '2026-12-01')->where('status', 'scheduled')->sole()->total)->toBe('400.000');

    // B moves out on its last day; its settlement takes 50 for cleaning; the rest is refunded.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Bahrain'));
    app(RecordMoveOut::class)->handle($leasing, $agreement->fresh(), $auB->fresh(), '2026-11-15', null, 'Keys back');
    $settlement = DepositSettlement::sole();
    app(SaveSettlementDeductions::class)->handle($finance, $settlement, [['agreement_unit_id' => $auB->id, 'type' => 'cleaning', 'description' => 'End clean', 'amount' => '50.000']]);
    app(DecideApproval::class)->handle($management, app(SubmitDepositSettlement::class)->handle($finance, $settlement->fresh()), true);
    app(RecordDisbursement::class)->handle($finance, ['purpose' => 'deposit_refund', 'deposit_settlement_id' => $settlement->id, 'amount' => '250.000', 'method' => 'bank_transfer', 'paid_on' => '2026-11-16']);

    // The 02:00 job leaves the agreement active (unit A is still let).
    app(ExpireAgreements::class)();

    expect($settlement->fresh()->status->value)->toBe('completed')
        ->and(DepositMovement::heldFils($auB->id))->toBe(0)
        ->and(DepositMovement::heldFils($agreement->agreementUnits()->where('unit_id', $a->id)->value('id')))->toBe(400_000)
        ->and($agreement->fresh()->status->value)->toBe('active')
        ->and(app(IntegrityCheck::class)->run())->toBe([]);

    // The statement equals the cached figures (spec §7.10).
    $issued = Invoice::where('customer_id', $customer->id)->where('status', 'issued')->where('type', '!=', 'credit_note');
    $outstanding = Fils::fromDecimal((string) $issued->sum('balance'));
    expect(CustomerStatement::receivables($customer, '2026-09-01', '2026-11-30')['closing'])->toBe($outstanding - CustomerCredit::fils($customer->id));
});
