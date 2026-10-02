<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Actions\Payments\RecordPayment;
use App\Billing\OwnerLedger;
use App\Billing\OwnerStatementCalculator;
use App\Enums\RoleName;
use App\Livewire\OwnerStatements\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00', 'require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $building = Building::factory()->create();
    $unit = Unit::factory()->for($building)->create();
    $this->contract = activeOwnerContract(['building_id' => $building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'deposits_held_by' => 'company', 'fee_type' => 'percent_collected', 'fee_value' => '10.000'], [$unit]);
    $customer = Customer::factory()->create();
    $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    issuedInvoice($customer, [['net' => '100.000', 'tax' => 'standard', 'au' => $agreement->agreementUnits()->sole()]], '2026-03-01', $agreement);
    app(RecordPayment::class)->handle($this->finance, $customer, ['received_on' => '2026-03-10', 'method' => 'cash', 'amount' => '110.000']);
    $this->travelTo(CarbonImmutable::parse('2026-04-01 04:00', 'Asia/Bahrain'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-03-01'));
    $this->march = OwnerStatement::where('owner_contract_id', $this->contract->id)->sole(); // closing 89.000 = 100 − 11
    $this->travelTo(CarbonImmutable::parse('2026-04-03 10:00', 'Asia/Bahrain'));
    $this->remit = fn (string $amount, ?OwnerStatement $s = null) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'owner_remittance', 'owner_statement_id' => ($s ?? $this->march)->id, 'amount' => $amount, 'method' => 'bank_transfer', 'paid_on' => '2026-04-03',
    ]);
});

test('only a finalised statement can be remitted', function () {
    expect(fn () => ($this->remit)('89.000'))->toThrow(ValidationException::class, 'finalised');
});

test('§14 flow 14: within the live balance it is paid at once; beyond it, it needs approval', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $this->march), true);

    $out = ($this->remit)('89.000');
    expect($out->status->value)->toBe('paid')->and($out->payee_id)->toBe($this->contract->owner_id)
        ->and(OwnerLedger::balance($this->contract))->toBe(0);

    expect(($this->remit)('1.000')->status->value)->toBe('pending_approval');
});

test('remittances waiting for approval count against the limit', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $this->march), true);

    expect(($this->remit)('100.000')->status->value)->toBe('pending_approval') // above 89
        ->and(($this->remit)('10.000')->status->value)->toBe('pending_approval')  // 89 − 100 waiting < 10
        ->and(OwnerLedger::remittableFils($this->contract))->toBe(-21_000);
});

test('a remittance posts on the next statement; its reversal posts at the reversal time', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $this->march), true);
    $out = ($this->remit)('89.000');
    $this->travel(2)->days();
    app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $out, 'Bounced by the bank'), true);

    $this->travelTo(CarbonImmutable::parse('2026-05-01 04:00', 'Asia/Bahrain'));
    app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse('2026-04-01'));
    $april = OwnerStatement::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-04-01')->sole();

    expect(OwnerStatementCalculator::compute($april)['entries']->map(fn ($e) => [$e['kind'], $e['date'], $e['amount']])->all())->toBe([
        ['remittance', '2026-04-03', -89_000],
        ['remittance_reversal', '2026-04-05', 89_000],
    ])->and($april->closing_balance)->toBe('89.000');
});

test('the statement page remits and warns about a recent bank change', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $this->march), true);
    $this->contract->owner->forceFill(['bank_changed_at' => now()->subDays(3), 'bank_changed_by' => $this->management->id])->save();

    Livewire::actingAs($this->finance)->test(Show::class, ['statement' => $this->march->fresh()])
        ->assertSee('Bank details changed')->assertSee($this->management->name)
        ->assertSet('remittance.amount', '89.000')
        ->set('remittance.paid_on', '2026-04-03')
        ->call('remit')->assertHasNoErrors();

    expect(OwnerLedger::balance($this->contract))->toBe(0);
});
