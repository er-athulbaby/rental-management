<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\AddWorkOrder;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->plumber = maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]);
});

test('a request is numbered MR-YYYY-NNNNNN, starts open, takes the unit\'s current tenant and starts the timeline', function () {
    $request = maintenanceRequest($this->pm, $this->unit, ['priority' => 'urgent']);

    expect($request->number)->toBe('MR-2026-000001')
        ->and($request->status)->toBe(MaintenanceStatus::Open)
        ->and($request->customer_id)->toBe($this->tenant->id)
        ->and($request->priority)->toBe(MaintenancePriority::Urgent)
        ->and($request->created_by)->toBe($this->pm->id)
        ->and($request->place())->toBe('Seef Tower 101')
        ->and($request->events()->get(['kind', 'from_status', 'to_status'])->toArray())->toBe([['kind' => 'status', 'from_status' => null, 'to_status' => 'open']])
        ->and(Activity::query()->where('subject_type', MaintenanceRequest::class)->where('subject_id', $request->id)->where('event', 'created')->exists())->toBeTrue()
        ->and(maintenanceRequest($this->pm, $this->unit)->number)->toBe('MR-2026-000002');
});

test('a unit with no current agreement has no tenant; a common-area request has no unit', function () {
    $vacant = Unit::factory()->for($this->building)->create();
    expect(MaintenanceRequest::currentTenantId($vacant->id))->toBeNull();

    $common = maintenanceRequest($this->pm, $this->unit, ['unit_id' => null, 'customer_id' => null]);
    expect($common->unit_id)->toBeNull()->and($common->customer_id)->toBeNull()->and($common->place())->toBe('Seef Tower');
});

test('request input is validated and scoped to what the user can see', function (array $change, string $error) {
    $other = Building::factory()->create();
    $change = array_map(fn ($v) => match ($v) {
        ':other-building' => $other->id,
        ':other-unit' => Unit::factory()->for($other)->create()->id,
        ':hidden-tenant' => activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
            [Unit::factory()->for($other)->create()])->customer_id,
        default => $v,
    }, $change);
    $scoped = User::factory()->create()->givePermissionTo(['maintenance.view', 'maintenance.manage']);
    $scoped->buildings()->attach($this->building->id);

    try {
        maintenanceRequest($scoped, $this->unit, $change);
        $this->fail('The request was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($error);
    }
    expect(MaintenanceRequest::count())->toBe(0);
})->with([
    'a building the user cannot see' => [['building_id' => ':other-building', 'unit_id' => null], 'building_id'],
    'a unit of another building' => [['unit_id' => ':other-unit'], 'unit_id'],
    'a tenant the user cannot see' => [['customer_id' => ':hidden-tenant'], 'customer_id'],
    'unknown channel' => [['channel' => 'fax'], 'channel'],
    'unknown category' => [['category' => 'roofing'], 'category'],
    'unknown priority' => [['priority' => 'asap'], 'priority'],
    'no description' => [['description' => ''], 'description'],
    'description too long' => [['description' => str_repeat('x', 2001)], 'description'],
    'no reporter' => [['reported_by_name' => ''], 'reported_by_name'],
    'reporter too long' => [['reported_by_name' => str_repeat('x', 151)], 'reported_by_name'],
    'building as an array' => [['building_id' => ['1']], 'building_id'],
    'tenant as text' => [['customer_id' => 'abc'], 'customer_id'],
    'entry permission not a boolean' => [['entry_permitted' => 'maybe'], 'entry_permitted'],
]);

test('work orders are numbered, go to vendors whose trades include the category or general, and the first one starts the request', function () {
    $request = maintenanceRequest($this->pm, $this->unit);
    $first = workOrderFor($this->pm, $request, $this->plumber, ['scheduled_on' => '2026-10-19', 'instructions' => 'Replace the trap', 'estimate' => '25.5']);

    expect($first->number)->toBe('WO-2026-000001')
        ->and($first->status)->toBe(WorkOrderStatus::Assigned)
        ->and($first->estimate)->toBe('25.500')
        ->and($first->scheduled_on->format('d/m/Y'))->toBe('19/10/2026')
        ->and($request->fresh()->status)->toBe(MaintenanceStatus::InProgress);

    workOrderFor($this->pm, $request, maintenanceVendor(['name' => 'Handyman', 'trades' => ['general']]));
    expect($request->workOrders()->count())->toBe(2)
        ->and($request->events()->get()->map(fn ($e) => [$e->work_order_id !== null, $e->from_status, $e->to_status])->all())
        ->toBe([[false, null, 'open'], [true, null, 'assigned'], [false, 'open', 'in_progress'], [true, null, 'assigned']]);
});

test('an inactive vendor, or one without the trade, gets no work order', function () {
    $request = maintenanceRequest($this->pm, $this->unit);

    foreach ([maintenanceVendor(['trades' => ['plumbing'], 'active' => false]), maintenanceVendor(['trades' => ['ac', 'painting']])] as $vendor) {
        expect(fn () => workOrderFor($this->pm, $request, $vendor))->toThrow(ValidationException::class);
    }
    expect(WorkOrder::count())->toBe(0)->and($request->fresh()->status)->toBe(MaintenanceStatus::Open);
});

test('work order input is validated', function (array $data, string $error) {
    $request = maintenanceRequest($this->pm, $this->unit);

    try {
        app(AddWorkOrder::class)->handle($this->pm, $request, ['vendor_id' => $this->plumber->id, ...$data]);
        $this->fail('The work order was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($error);
    }
    expect(WorkOrder::count())->toBe(0);
})->with([
    'no vendor' => [['vendor_id' => null], 'vendor_id'],
    'unknown vendor' => [['vendor_id' => 999999], 'vendor_id'],
    'vendor as an array' => [['vendor_id' => ['1']], 'vendor_id'],
    'date not Y-m-d' => [['scheduled_on' => '19/10/2026'], 'scheduled_on'],
    'negative estimate' => [['estimate' => '-5'], 'estimate'],
    'four decimals' => [['estimate' => '1.2345'], 'estimate'],
    'estimate as an array' => [['estimate' => ['1']], 'estimate'],
    'instructions too long' => [['instructions' => str_repeat('x', 2001)], 'instructions'],
]);

test('only maintenance.manage, in the request\'s building, logs requests and adds work orders', function () {
    $request = maintenanceRequest($this->pm, $this->unit);

    foreach ([R::Leasing, R::Management, R::Finance] as $role) {
        $user = matrixUser($role, $this->building);
        expect(fn () => maintenanceRequest($user, $this->unit))->toThrow(AuthorizationException::class)
            ->and(fn () => workOrderFor($user, $request, $this->plumber))->toThrow(AuthorizationException::class);
    }
    $elsewhere = User::factory()->create()->givePermissionTo(['maintenance.view', 'maintenance.manage']); // no building assigned
    expect(fn () => workOrderFor($elsewhere, $request, $this->plumber))->toThrow(AuthorizationException::class)
        ->and(workOrderFor(matrixUser(R::Admin, $this->building), $request, $this->plumber)->exists)->toBeTrue();
});

test('the database refuses a completed work order without its completion date', function () {
    $wo = workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unit), $this->plumber);

    expect(fn () => $wo->forceFill(['status' => 'completed'])->save())->toThrow(QueryException::class);
});

test('the action stores the customer exactly as given and refuses one the user cannot see', function () {
    $other = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for(Building::factory()->create())->create()])->customer_id;
    expect(maintenanceRequest($this->pm, $this->unit, ['customer_id' => null])->customer_id)->toBeNull()
        ->and(maintenanceRequest($this->pm, $this->unit, ['customer_id' => $this->tenant->id])->customer_id)->toBe($this->tenant->id);

    $scoped = User::factory()->create()->givePermissionTo(['maintenance.view', 'maintenance.manage']);
    $scoped->buildings()->attach($this->building->id);
    expect(fn () => maintenanceRequest($scoped, $this->unit, ['customer_id' => $other]))->toThrow(ValidationException::class);
});

test('text is trimmed before it is saved', function () {
    $request = maintenanceRequest($this->pm, $this->unit, ['description' => '  Leaking  ', 'reported_by_name' => ' Sara ']);
    expect($request->description)->toBe('Leaking')->and($request->reported_by_name)->toBe('Sara');
});

test('a resolved or cancelled request takes no work order', function (string $status) {
    $request = maintenanceRequest($this->pm, $this->unit);
    $request->forceFill(['status' => $status, 'resolved_at' => $status === 'resolved' ? now() : null, 'cancel_reason' => $status === 'cancelled' ? 'Duplicate' : null])->save();

    expect(fn () => workOrderFor($this->pm, $request, $this->plumber))->toThrow(ValidationException::class)
        ->and(WorkOrder::count())->toBe(0);
})->with(['resolved', 'cancelled']);

test('a missing number sequence row rolls everything back and consumes no number', function () {
    DB::table('number_sequences')->where('key', 'maintenance_request')->delete();
    expect(fn () => maintenanceRequest($this->pm, $this->unit))->toThrow(RuntimeException::class)
        ->and(MaintenanceRequest::count())->toBe(0)->and(MaintenanceEvent::count())->toBe(0);

    app(EnsureNumberSequences::class)(2026);
    $request = maintenanceRequest($this->pm, $this->unit);
    expect($request->number)->toBe('MR-2026-000001');

    DB::table('number_sequences')->where('key', 'work_order')->delete();
    expect(fn () => workOrderFor($this->pm, $request, $this->plumber))->toThrow(RuntimeException::class)
        ->and(WorkOrder::count())->toBe(0)->and(MaintenanceEvent::count())->toBe(1)
        ->and($request->fresh()->status)->toBe(MaintenanceStatus::Open);
});

test('a work order writes a timeline row and audit rows', function () {
    $request = maintenanceRequest($this->pm, $this->unit);
    $wo = workOrderFor($this->pm, $request, $this->plumber);

    $audit = fn (string $type, int $id, string $event) => Activity::query()->where('subject_type', $type)->where('subject_id', $id)->where('event', $event)->exists();
    $event = $request->events()->where('work_order_id', $wo->id)->first();

    expect($event->describe())->toBe('WO-2026-000001: Assigned')
        ->and($audit(WorkOrder::class, $wo->id, 'created'))->toBeTrue()
        ->and($audit(MaintenanceEvent::class, $event->id, 'created'))->toBeTrue()
        ->and($audit(MaintenanceRequest::class, $request->id, 'updated'))->toBeTrue();
});
