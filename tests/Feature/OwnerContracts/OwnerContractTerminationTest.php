<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\OwnerContracts\CloseEndedOwnerContracts;
use App\Actions\OwnerContracts\RequestOwnerContractTermination;
use App\Enums\ApprovalAction;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Livewire\OwnerContracts\Show;
use App\Models\CompanySetting;
use App\Models\OwnerContract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->contract = activeOwnerContract(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'created_by' => $this->finance->id], []);
});

test('an approved early termination moves the end date; the job then marks it terminated', function () {
    $approval = app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-10-31', 'Owner sold the building');

    expect($approval->action)->toBe(ApprovalAction::OwnerContractTermination)
        ->and($approval->payload)->toBe(['terminated_on' => '2026-10-31', 'termination_reason' => 'Owner sold the building'])
        ->and($this->contract->fresh()->terminated_on)->toBeNull(); // nothing changes until approved

    app(DecideApproval::class)->handle($this->management, $approval, true);

    $contract = $this->contract->fresh();
    expect($contract->end_date->toDateString())->toBe('2026-10-31')
        ->and($contract->terminated_on->toDateString())->toBe('2026-10-31')
        ->and($contract->status)->toBe(OwnerContractStatus::Active);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 02:15', 'Asia/Bahrain'));
    expect(app(CloseEndedOwnerContracts::class)())->toBe(1)
        ->and(app(CloseEndedOwnerContracts::class)())->toBe(0) // idempotent
        ->and($contract->fresh()->status)->toBe(OwnerContractStatus::Terminated);
});

test('a rejected termination leaves the contract unchanged', function () {
    $approval = app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-10-31', 'Dispute');
    app(DecideApproval::class)->handle($this->management, $approval, false, 'Settle the dispute first');

    expect($this->contract->fresh()->end_date->toDateString())->toBe('2026-12-31')
        ->and($this->contract->fresh()->terminated_on)->toBeNull();
});

test('the termination date must fall before the current end and not before the start', function (string $date) {
    expect(fn () => app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, $date, 'x'))
        ->toThrow(ValidationException::class);
})->with(['2026-12-31', '2027-02-01', '2025-12-31', 'not-a-date']);

test('only one termination request can be pending, and only active contracts can be terminated', function () {
    app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-10-31', 'x');

    expect(fn () => app(RequestOwnerContractTermination::class)->handle($this->finance, $this->contract, '2026-11-30', 'y'))
        ->toThrow(ValidationException::class, 'already waiting for approval');

    $draft = OwnerContract::factory()->create();
    expect(fn () => app(RequestOwnerContractTermination::class)->handle($this->finance, $draft, '2026-10-31', 'x'))
        ->toThrow(ValidationException::class);
});

test('the job ends contracts past their end date that were never terminated', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-01 02:15', 'Asia/Bahrain'));

    $this->artisan('rms:owner-contracts:close')->assertSuccessful();

    expect($this->contract->fresh()->status)->toBe(OwnerContractStatus::Ended);
});

test('Finance requests termination from the contract page', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $this->contract])
        ->set('terminatedOn', '2026-11-30')
        ->set('terminationReason', 'Owner request')
        ->call('requestTermination')
        ->assertHasNoErrors();

    expect($this->contract->approvals()->where('action', 'owner_contract.terminate')->where('status', 'pending')->exists())->toBeTrue();
});
