<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\ReverseExpense;
use App\Actions\OwnerStatements\DraftOwnerStatements;
use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Actions\Payments\RecordPayment;
use App\Billing\OwnerStatementCalculator;
use App\Enums\RoleName;
use App\Jobs\StoreOwnerStatement;
use App\Livewire\OwnerStatements\Show;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\OwnerCharge;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

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
    $this->expense = app(RecordExpense::class)->handle($this->finance, ['building_id' => $building->id, 'unit_id' => $unit->id, 'category' => 'maintenance', 'description' => 'Pump', 'expense_date' => '2026-03-10', 'net' => '20.000', 'tax_amount' => '2.000', 'charge_to' => 'owner']);
    $this->draftFor = function (string $month) {
        $this->travelTo(CarbonImmutable::parse($month.'-01 04:00', 'Asia/Bahrain')->addMonth());
        app(DraftOwnerStatements::class)->handle(CarbonImmutable::parse($month.'-01'));

        return OwnerStatement::where('owner_contract_id', $this->contract->id)->where('period_start', $month.'-01')->sole();
    };
    $this->finalise = fn (OwnerStatement $s) => app(DecideApproval::class)->handle($this->management, app(SubmitOwnerStatement::class)->handle($this->finance, $s), true);
});

test('finalising writes the fee charge at the cutoff, numbers the statement and stores its PDF', function () {
    $march = ($this->draftFor)('2026-03');
    ($this->finalise)($march);

    $march->refresh();
    $fee = OwnerCharge::where('owner_statement_id', $march->id)->sole();
    expect($march->status->value)->toBe('finalised')->and($march->number)->toStartWith('OS-2026-')
        ->and([$fee->type->value, $fee->net, $fee->tax_amount, $fee->amount, $fee->posted_at->toDateTimeString()])
        ->toBe(['management_fee', '10.000', '1.000', '-11.000', '2026-03-31 23:59:59'])
        ->and(StoreOwnerStatement::stored($march))->not->toBeNull()
        ->and(OwnerStatementCalculator::compute($march)['closing'])->toBe(67_000); // the fee row is not counted twice
});

test('§14 flow 6: a reversed expense shows on the later statement; the finalised one is unchanged', function () {
    $march = ($this->draftFor)('2026-03');
    ($this->finalise)($march);
    $this->travelTo(CarbonImmutable::parse('2026-04-05 10:00', 'Asia/Bahrain'));
    app(ReverseExpense::class)->handle($this->finance, $this->expense, 'Duplicate');
    $april = ($this->draftFor)('2026-04');

    expect($march->fresh()->closing_balance)->toBe('67.000')
        ->and(OwnerStatementCalculator::compute($march->fresh())['entries']->pluck('kind')->all())->toBe(['collection', 'expense'])
        ->and(OwnerStatementCalculator::compute($april)['entries']->map(fn ($e) => [$e['kind'], $e['amount']])->all())->toBe([['expense_reversal', 22_000]])
        ->and($april->opening_balance)->toBe('67.000')->and($april->closing_balance)->toBe('89.000');
});

test('a statement is submitted only after the previous one is finalised; rejection returns it to draft', function () {
    $march = ($this->draftFor)('2026-03');
    $april = ($this->draftFor)('2026-04');

    expect(fn () => app(SubmitOwnerStatement::class)->handle($this->finance, $april))->toThrow(ValidationException::class, 'previous statement');

    $approval = app(SubmitOwnerStatement::class)->handle($this->finance, $march);
    expect($march->fresh()->status->value)->toBe('pending_approval');
    app(DecideApproval::class)->handle($this->management, $approval, false, 'Check the expense');
    expect($march->fresh()->status->value)->toBe('draft')->and(OwnerCharge::count())->toBe(0);
});

test('the statement page shows the figures and submits; PDF and Excel downloads are audited', function () {
    $march = ($this->draftFor)('2026-03');

    Livewire::actingAs($this->finance)->test(Show::class, ['statement' => $march])
        ->assertSee('67.000')->assertSee('Pump')->call('submit')->assertHasNoErrors();
    expect($march->fresh()->status->value)->toBe('pending_approval');

    $this->actingAs($this->finance)->get(route('owner-statements.pdf', $march))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->finance)->get(route('owner-statements.export', $march))->assertOk()->assertDownload();
    expect(Activity::where('event', 'owner_statement.exported')->count())->toBe(2);
});

test('a finalised statement keeps its stored figures when the VAT rate later changes', function () {
    $march = ($this->draftFor)('2026-03');
    ($this->finalise)($march);
    CompanySetting::current()->update(['vat_rate' => '5.00']);

    Livewire::actingAs($this->finance)->test(Show::class, ['statement' => $march])->assertSee('1.000')->assertSee('67.000');
    $stored = OwnerStatementCalculator::stored($march->fresh());
    expect([$stored['fee_tax'], $stored['closing']])->toBe([1_000, 67_000])
        ->and(OwnerStatementCalculator::compute($march->fresh())['fee_tax'])->toBe(500);
    $this->actingAs($this->finance)->get(route('owner-statements.export', $march))->assertOk()->assertDownload();
});

test('a statement whose cutoff has not passed cannot be submitted', function () {
    $s = (new OwnerStatement)->forceFill(['owner_contract_id' => $this->contract->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'cutoff_at' => '2026-03-31 23:59:59', 'status' => 'draft', 'created_by' => $this->contract->created_by]);
    $s->save();

    expect(fn () => app(SubmitOwnerStatement::class)->handle($this->finance, $s))->toThrow(ValidationException::class, 'has not ended')
        ->and($s->fresh()->status->value)->toBe('draft');
});
