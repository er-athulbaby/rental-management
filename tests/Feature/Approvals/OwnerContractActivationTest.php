<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\EnsureNumberSequences;
use App\Actions\OwnerContracts\SaveOwnerContract;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Enums\ApprovalStatus;
use App\Enums\OwnerContractStatus;
use App\Enums\RoleName;
use App\Models\Approval;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);

    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $this->building = Building::factory()->create();
    $this->units = Unit::factory()->for($this->building)->count(2)->create();
    $this->owner = Owner::factory()->create();
    $this->terms = [
        'owner_id' => $this->owner->id, 'building_id' => $this->building->id, 'type' => 'managed',
        'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
        'fee_type' => 'percent_collected', 'fee_value' => '5', 'deposits_held_by' => 'company',
        'unit_ids' => $this->units->pluck('id')->all(),
    ];
    $this->draft = app(SaveOwnerContract::class)->handle($this->finance, null, $this->terms);
});

test('submitting locks the draft, opens one pending approval and emails the other approvers', function () {
    Notification::fake();
    $otherApprover = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);

    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);

    expect($this->draft->fresh()->status)->toBe(OwnerContractStatus::PendingApproval)
        ->and($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->requested_by)->toBe($this->finance->id);

    Notification::assertSentTo([$this->management, $otherApprover], ApprovalRequested::class);
    Notification::assertNotSentTo($this->finance, ApprovalRequested::class);

    expect(fn () => app(SaveOwnerContract::class)->handle($this->finance, $this->draft->fresh(), $this->terms))
        ->toThrow(ValidationException::class, 'Only draft contracts can be edited.');
    expect(fn () => app(SubmitOwnerContract::class)->handle($this->finance, $this->draft->fresh()))
        ->toThrow(ValidationException::class);
});

test('Management approves: the contract becomes active with the next OC number', function () {
    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);

    app(DecideApproval::class)->handle($this->management, $approval, true);

    $contract = $this->draft->fresh();
    expect($contract->status)->toBe(OwnerContractStatus::Active)
        ->and($contract->number)->toBe('OC-2026-000001')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->fresh()->decided_by)->toBe($this->management->id)
        ->and(Activity::query()->where('event', 'approval.approved')->where('causer_id', $this->management->id)->exists())->toBeTrue();
});

test('neither the requester nor the creator can approve while require_different_approver is on', function () {
    $dual = User::factory()->withTwoFactor()->create()->assignRole([RoleName::Finance, RoleName::Management]);

    // The dual user CREATED it; Finance submits.
    $created = app(SaveOwnerContract::class)->handle($dual, null, $this->terms);
    $a = app(SubmitOwnerContract::class)->handle($this->finance, $created);
    expect(fn () => app(DecideApproval::class)->handle($dual, $a, true))->toThrow(ValidationException::class, 'you requested or a record you created');

    // Finance created it; the dual user REQUESTED approval.
    $other = app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->terms, 'start_date' => '2028-01-01', 'end_date' => '2028-12-31']);
    $b = app(SubmitOwnerContract::class)->handle($dual, $other);
    expect(fn () => app(DecideApproval::class)->handle($dual, $b, true))->toThrow(ValidationException::class);

    CompanySetting::current()->forceFill(['require_different_approver' => false])->save();
    app(DecideApproval::class)->handle($dual, $b, true);
    expect($other->fresh()->status)->toBe(OwnerContractStatus::Active);
});

test('only approvals.decide holders decide, and a rejection needs a reason and returns the draft', function () {
    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);

    expect(fn () => app(DecideApproval::class)->handle($this->finance, $approval, true))->toThrow(AuthorizationException::class);
    expect(fn () => app(DecideApproval::class)->handle($this->management, $approval, false, ''))->toThrow(ValidationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, false, 'Fee should be 6%');

    expect($this->draft->fresh()->status)->toBe(OwnerContractStatus::Draft)
        ->and($approval->fresh()->comment)->toBe('Fee should be 6%');

    // Fix and resubmit: a new pending approval is allowed once the old one is decided.
    app(SaveOwnerContract::class)->handle($this->finance, $this->draft->fresh(), [...$this->terms, 'fee_value' => '6']);
    $again = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft->fresh());
    expect($again->id)->not->toBe($approval->id);

    expect(fn () => app(DecideApproval::class)->handle($this->management, $approval->fresh(), true))->toThrow(ValidationException::class, 'already been decided');
});

test('the database keeps one pending request per record and action, and decided approvals immutable', function () {
    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);
    $copy = collect($approval->getAttributes())->except(['id', 'pending_key'])->all();

    expect(fn () => DB::table('approvals')->insert($copy))->toThrow(UniqueConstraintViolationException::class);

    app(DecideApproval::class)->handle($this->management, $approval, true);

    expect(fn () => DB::table('approvals')->where('id', $approval->id)->update(['comment' => 'edited']))->toThrow(QueryException::class, 'decided approvals are immutable');
    expect(fn () => DB::table('approvals')->where('id', $approval->id)->delete())->toThrow(QueryException::class, 'approvals cannot be deleted');
});

test('a unit cannot be covered twice on the same dates; a later pre-arranged contract is fine', function () {
    app(SubmitOwnerContract::class)->handle($this->finance, $this->draft);

    $clash = app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->terms, 'owner_id' => Owner::factory()->create()->id, 'unit_ids' => [$this->units[1]->id], 'start_date' => '2027-06-01', 'end_date' => '2028-05-31']);
    expect(fn () => app(SubmitOwnerContract::class)->handle($this->finance, $clash))->toThrow(ValidationException::class, $this->units[1]->code);
    expect($clash->fresh()->status)->toBe(OwnerContractStatus::Draft);

    $later = app(SaveOwnerContract::class)->handle($this->finance, null, [...$this->terms, 'start_date' => '2027-11-01', 'end_date' => '2028-10-31']);
    expect(app(SubmitOwnerContract::class)->handle($this->finance, $later)->status)->toBe(ApprovalStatus::Pending);
});

test('approving a successor ends its predecessor the day before', function () {
    app(DecideApproval::class)->handle($this->management, app(SubmitOwnerContract::class)->handle($this->finance, $this->draft), true);

    $successor = app(SaveOwnerContract::class)->handle($this->finance, null, [
        ...$this->terms, 'fee_value' => '6', 'start_date' => '2027-05-01', 'end_date' => '2028-04-30', 'previous_contract_id' => $this->draft->id,
    ]);
    $approval = app(SubmitOwnerContract::class)->handle($this->finance, $successor); // overlap with the predecessor is allowed
    app(DecideApproval::class)->handle($this->management, $approval, true);

    expect($this->draft->fresh()->end_date->toDateString())->toBe('2027-04-30')
        ->and($successor->fresh()->number)->toBe('OC-2026-000002');
});
