<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Disbursements\PayDisbursement;
use App\Actions\Disbursements\RecordDisbursement;
use App\Actions\Disbursements\RequestDisbursementReversal;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerContracts\RequestOwnerContractTermination;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Enums\RoleName;
use App\Livewire\OwnerPayables\Index;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\OwnerPayable;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-01-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $building = Building::factory()->create();
    $unit = Unit::factory()->for($building)->create();
    $draft = app(SaveOwnerContract::class)->handle($this->finance, null, [
        'owner_id' => Owner::factory()->create()->id, 'building_id' => $building->id, 'type' => 'leased',
        'start_date' => '2026-01-01', 'end_date' => '2026-08-31', 'rent_amount' => '3000.000', 'payment_frequency' => 'quarterly', 'unit_ids' => [$unit->id],
    ]);
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerContract::class)->handle($this->finance, $draft), true);
    $this->contract = $draft->fresh();
    $this->q1 = OwnerPayable::where('owner_contract_id', $this->contract->id)->orderBy('period_start')->first();
    $this->pay = fn (string $amount, ?OwnerPayable $payable = null) => app(RecordDisbursement::class)->handle($this->finance, [
        'purpose' => 'head_lease', 'owner_payable_id' => ($payable ?? $this->q1)->id, 'amount' => $amount, 'method' => 'bank_transfer', 'paid_on' => '2026-01-05',
    ]);
});

test('§14 flow 7: a scheduled payable is paid once, for its amount, to the contract\'s owner', function () {
    $out = ($this->pay)('3000.000');

    expect($out->status->value)->toBe('paid')->and($out->number)->not->toBeNull()
        ->and($out->payee_id)->toBe($this->contract->owner_id)->and($out->source_type)->toBe('owner_payable')
        ->and($this->q1->fresh()->status->value)->toBe('paid')->and($this->q1->fresh()->disbursement_id)->toBe($out->id);
});

test('§14 flow 14: a second payment of the same payable, or a different amount, needs approval', function () {
    $first = ($this->pay)('3000.000');
    $second = ($this->pay)('3000.000');
    $q2 = OwnerPayable::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-04-01')->sole();
    $odd = ($this->pay)('2999.000', $q2);

    expect($second->status->value)->toBe('pending_approval')->and($odd->status->value)->toBe('pending_approval')
        ->and(Approval::where('approvable_id', $second->id)->where('action', 'payment_out.approve')->exists())->toBeTrue()
        ->and($q2->fresh()->status->value)->toBe('scheduled');

    // While the odd one waits, even the exact amount goes to approval.
    expect(($this->pay)('3000.000', $q2)->status->value)->toBe('pending_approval');

    // Approved and paid: the first payable stays paid by its first payment.
    app(DecideApproval::class)->handle($this->management, Approval::where('approvable_id', $second->id)->sole(), true);
    app(PayDisbursement::class)->handle($this->finance, $second->fresh(), ['method' => 'bank_transfer', 'paid_on' => '2026-01-05']);
    expect($this->q1->fresh()->disbursement_id)->toBe($first->id);
});

test('a cancelled payable cannot be paid', function () {
    app(DecideApproval::class)->handle($this->management, app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-03-31', 'Sold'), true);
    $q3 = OwnerPayable::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-07-01')->sole();

    expect(fn () => ($this->pay)('2000.000', $q3))->toThrow(ValidationException::class, 'cancelled');
});

test('reversing the payment out reopens the payable, or cancels it when the contract now ends earlier', function () {
    $q2 = OwnerPayable::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-04-01')->sole();
    $out = ($this->pay)('3000.000', $q2);
    $reverse = fn ($o) => app(DecideApproval::class)->handle($this->management, app(RequestDisbursementReversal::class)->handle($this->finance, $o, 'Wrong account'), true);

    $reverse($out);
    expect($q2->fresh()->status->value)->toBe('scheduled')->and($q2->fresh()->disbursement_id)->toBeNull();

    $again = ($this->pay)('3000.000', $q2);
    app(DecideApproval::class)->handle($this->management, app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-05-15', 'Sold'), true);
    expect($q2->fresh()->status->value)->toBe('paid'); // plan ruling 3: paid payables are not re-cut

    $reverse($again);
    $live = OwnerPayable::where('owner_contract_id', $this->contract->id)->where('period_start', '2026-04-01')->where('status', 'scheduled')->sole();
    expect($q2->fresh()->status->value)->toBe('cancelled')->and($live->period_end->toDateString())->toBe('2026-05-15')->and($live->amount)->toBe('1493.151');
});

test('the head-lease payments due page lists scheduled payables and pays one', function () {
    Livewire::actingAs($this->finance)->test(Index::class)
        ->assertSee($this->contract->number)->assertSee('3000.000')
        ->call('startPaying', $this->q1->id)
        ->set('form.method', 'bank_transfer')->set('form.paid_on', '2026-01-05')
        ->call('pay')->assertHasNoErrors();

    expect($this->q1->fresh()->status->value)->toBe('paid');
});
