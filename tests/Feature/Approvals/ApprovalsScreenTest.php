<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Livewire\Approvals\Index;
use App\Livewire\Documents\Panel;
use App\Livewire\OwnerContracts\Show;
use App\Models\CompanySetting;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureNumberSequences::class)(now('Asia/Bahrain')->year);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->contract = OwnerContract::factory()->create(['created_by' => $this->finance->id]);
    $this->contract->units()->attach(Unit::factory()->create(['building_id' => $this->contract->building_id])->id);
});

test('Finance submits from the contract page', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $this->contract])
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->contract->fresh()->status)->toBe(OwnerContractStatus::PendingApproval);
});

test('Management approves and rejects from the pending list', function () {
    app(SubmitOwnerContract::class)->handle($this->finance, $this->contract);
    $approval = $this->contract->approvals()->sole();

    Livewire::actingAs($this->management)->test(Index::class)
        ->assertSee('Owner contract activation')
        ->call('startRejecting', $approval->id)
        ->call('reject')
        ->assertHasErrors('comment')
        ->set('comment', 'Wrong dates')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertSee('Nothing is waiting for approval.');

    expect($this->contract->fresh()->status)->toBe(OwnerContractStatus::Draft);

    app(SubmitOwnerContract::class)->handle($this->finance, $this->contract->fresh());
    Livewire::actingAs($this->management)->test(Index::class)
        ->call('approve', $this->contract->approvals()->where('status', 'pending')->sole()->id);

    expect($this->contract->fresh()->status)->toBe(OwnerContractStatus::Active);
});

test('the pending list is only for approvers', function () {
    $this->actingAs($this->finance)->get(route('approvals.index'))->assertForbidden();
    $this->actingAs($this->management)->get(route('approvals.index'))->assertOk();
});

test('the contract page renders its documents panel', function () {
    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $this->contract])
        ->assertSeeLivewire(Panel::class);
});

test('a failed overlap re-check on approval shows as an approval error and leaves the contract pending', function () {
    app(SubmitOwnerContract::class)->handle($this->finance, $this->contract);
    $approval = $this->contract->approvals()->sole();

    // A clashing contract goes active on the same unit and dates after the submit.
    activeOwnerContract(
        ['building_id' => $this->contract->building_id, 'start_date' => $this->contract->start_date, 'end_date' => $this->contract->end_date],
        $this->contract->units->pluck('id'),
    );

    Livewire::actingAs($this->management)->test(Index::class)
        ->call('approve', $approval->id)
        ->assertHasErrors('approval')
        ->assertHasNoErrors('unit_ids');

    expect($this->contract->fresh()->status)->toBe(OwnerContractStatus::PendingApproval);
});
