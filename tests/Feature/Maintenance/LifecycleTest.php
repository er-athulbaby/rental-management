<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\AddMaintenanceNote;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Actions\Maintenance\ChangeWorkOrderStatus;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\MaintenanceEvent;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->vendor = maintenanceVendor();
    $this->request = maintenanceRequest($this->pm, $this->unit);
    $this->change = fn (WorkOrder $wo, string $to, ?string $reason = null) => app(ChangeWorkOrderStatus::class)->handle($this->pm, $wo, $to, $reason);
    // Completing with a cost is Task 4's action; these tests only need a completed row.
    $this->markCompleted = fn (WorkOrder $wo) => $wo->forceFill(['status' => 'completed', 'completed_on' => '2026-10-17', 'completion_note' => 'Done'])->save();
});

test('a work order starts, then is cancelled with a reason; both changes are on the timeline and in the audit log', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->vendor);

    ($this->change)($wo, 'in_progress');
    ($this->change)($wo, 'cancelled', '  Tenant fixed it  ');

    expect($wo->fresh()->status)->toBe(WorkOrderStatus::Cancelled)
        ->and($wo->fresh()->cancel_reason)->toBe('Tenant fixed it')
        ->and(MaintenanceEvent::query()->where('work_order_id', $wo->id)->orderBy('id')->get()->map(fn ($e) => $e->describe())->all())
        ->toBe([$wo->number.': Assigned', $wo->number.': Assigned → In progress', $wo->number.': In progress → Cancelled · Tenant fixed it'])
        ->and(Activity::query()->where('subject_type', WorkOrder::class)->where('subject_id', $wo->id)->where('event', 'updated')->count())->toBe(2);
});

test('status changes are refused when they make no sense, and cancelling needs a reason', function (string $from, string $to, ?string $reason, string $error) {
    $wo = workOrderFor($this->pm, $this->request, $this->vendor);
    $wo->forceFill(['status' => $from, 'completed_on' => $from === 'completed' ? '2026-10-17' : null])->save();

    try {
        ($this->change)($wo, $to, $reason);
        $this->fail('The change was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($error);
    }
    expect($wo->fresh()->status->value)->toBe($from);
})->with([
    'start twice' => ['in_progress', 'in_progress', null, 'status'],
    'start a cancelled one' => ['cancelled', 'in_progress', null, 'status'],
    'cancel a completed one' => ['completed', 'cancelled', 'Oops', 'status'],
    'complete through this action' => ['assigned', 'completed', null, 'status'],
    'an unknown status' => ['assigned', 'paused', null, 'status'],
    'cancel without a reason' => ['assigned', 'cancelled', '   ', 'reason'],
    'a reason too long' => ['assigned', 'cancelled', str_repeat('x', 501), 'reason'],
]);

test('when every work order is completed or cancelled, with one completed, the request resolves itself', function () {
    $done = workOrderFor($this->pm, $this->request, $this->vendor);
    $other = workOrderFor($this->pm, $this->request, $this->vendor);
    ($this->markCompleted)($done);

    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::InProgress);
    ($this->change)($other, 'cancelled', 'Not needed');

    $request = $this->request->fresh();
    expect($request->status)->toBe(MaintenanceStatus::Resolved)
        ->and($request->resolved_at)->not->toBeNull()
        ->and($request->events()->get()->last()->describe())->toBe('In progress → Resolved');
});

test('with every work order cancelled and none completed, the request stays in progress', function () {
    ($this->change)(workOrderFor($this->pm, $this->request, $this->vendor), 'cancelled', 'Vendor did not come');

    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::InProgress);
});

test('resolving by hand is refused while a work order is open, and allowed once none is', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->vendor);
    $resolve = fn () => app(ResolveMaintenanceRequest::class)->handle($this->pm, $this->request);

    expect($resolve)->toThrow(ValidationException::class, 'Complete or cancel its open work orders first.');
    ($this->change)($wo, 'cancelled', 'Fixed by the tenant');
    $resolve();

    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved)
        ->and($resolve)->toThrow(ValidationException::class, 'This request is already closed.');

    $plain = maintenanceRequest($this->pm, $this->unit); // no work orders at all: fixed by phone
    app(ResolveMaintenanceRequest::class)->handle($this->pm, $plain);
    expect($plain->fresh()->status)->toBe(MaintenanceStatus::Resolved);
});

test('cancelling a request needs a reason and cancels its open work orders, keeping completed ones', function () {
    $done = workOrderFor($this->pm, $this->request, $this->vendor);
    $started = workOrderFor($this->pm, $this->request, $this->vendor);
    $assigned = workOrderFor($this->pm, $this->request, $this->vendor);
    ($this->markCompleted)($done);
    ($this->change)($started, 'in_progress');

    expect(fn () => app(CancelMaintenanceRequest::class)->handle($this->pm, $this->request, ' '))->toThrow(ValidationException::class);
    app(CancelMaintenanceRequest::class)->handle($this->pm, $this->request, 'Duplicate of MR-2026-000002');

    $request = $this->request->fresh();
    expect($request->status)->toBe(MaintenanceStatus::Cancelled)
        ->and($request->cancel_reason)->toBe('Duplicate of MR-2026-000002')
        ->and($done->fresh()->status)->toBe(WorkOrderStatus::Completed)
        ->and($started->fresh()->status)->toBe(WorkOrderStatus::Cancelled)
        ->and($assigned->fresh()->cancel_reason)->toBe('Duplicate of MR-2026-000002')
        ->and($request->events()->get()->last()->describe())->toBe('In progress → Cancelled · Duplicate of MR-2026-000002');
});

test('a closed request takes no work order and no change, but still takes notes', function () {
    app(CancelMaintenanceRequest::class)->handle($this->pm, $this->request, 'Logged twice');

    expect(fn () => workOrderFor($this->pm, $this->request, $this->vendor))->toThrow(ValidationException::class, 'This request is closed.')
        ->and(fn () => app(CancelMaintenanceRequest::class)->handle($this->pm, $this->request, 'Again'))->toThrow(ValidationException::class)
        ->and(fn () => app(ResolveMaintenanceRequest::class)->handle($this->pm, $this->request))->toThrow(ValidationException::class);

    $note = app(AddMaintenanceNote::class)->handle($this->pm, $this->request, 'Tenant called again; told them it is a duplicate.');
    expect($note->kind)->toBe('note')->and($note->describe())->toBe('Tenant called again; told them it is a duplicate.');
});

test('notes are 1 to 1000 characters', function (string $note) {
    expect(fn () => app(AddMaintenanceNote::class)->handle($this->pm, $this->request, $note))->toThrow(ValidationException::class);
})->with(['empty' => [''], 'spaces' => ['   '], 'too long' => [str_repeat('x', 1001)]]);

test('Leasing and users outside the building cannot change anything', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->vendor);
    $other = Building::factory()->create();

    foreach ([matrixUser(R::Leasing, $this->building), matrixUser(R::Management, $this->building)] as $user) {
        expect(fn () => app(ChangeWorkOrderStatus::class)->handle($user, $wo, 'in_progress'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ResolveMaintenanceRequest::class)->handle($user, $this->request))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CancelMaintenanceRequest::class)->handle($user, $this->request, 'x'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(AddMaintenanceNote::class)->handle($user, $this->request, 'x'))->toThrow(AuthorizationException::class);
    }
    $outsider = User::factory()->create()->givePermissionTo(['maintenance.view', 'maintenance.manage']);
    $outsider->buildings()->attach($other->id);
    expect(fn () => app(ChangeWorkOrderStatus::class)->handle($outsider, $wo, 'in_progress'))->toThrow(AuthorizationException::class)
        ->and($wo->fresh()->status)->toBe(WorkOrderStatus::Assigned);
});
