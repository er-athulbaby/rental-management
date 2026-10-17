<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\RoleName as R;
use App\Livewire\Maintenance\RequestForm;
use App\Livewire\Maintenance\Requests;
use App\Livewire\Maintenance\VendorForm;
use App\Livewire\Maintenance\Vendors;
use App\Livewire\Maintenance\WorkOrders;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create(['code' => 'AAA', 'name' => 'Seef Tower']);
    $this->b = Building::factory()->create(['code' => 'BBB', 'name' => 'Juffair Heights']);
    $this->unitA = Unit::factory()->for($this->a)->create(['code' => '101']);
    $this->unitB = Unit::factory()->for($this->b)->create(['code' => '201']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'mobile' => '+973 3900 1111']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unitA]);
    $this->pm = matrixUser(R::PropertyManager, $this->a);
    $this->plumber = maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]);
    $this->cool = maintenanceVendor(['name' => 'Gulf Cool', 'trades' => ['ac']]);
    // MR-1 in progress (A), MR-2 urgent and open (B), MR-3 open (A), MR-4 resolved (A).
    $this->started = maintenanceRequest($this->pm, $this->unitA, ['description' => 'Old leak']);
    $this->firstWo = workOrderFor($this->pm, $this->started, $this->plumber);
    $this->urgent = maintenanceRequest($this->pm, $this->unitB, ['priority' => 'urgent', 'category' => 'ac', 'description' => 'AC not cooling']);
    $this->waiting = maintenanceRequest($this->pm, $this->unitA, ['description' => 'Dripping tap']);
    $this->done = maintenanceRequest($this->pm, $this->unitA, ['description' => 'Door handle']);
    app(ResolveMaintenanceRequest::class)->handle($this->pm, $this->done);
    $this->numbers = fn ($page, string $key = 'requests') => $page->viewData($key)->pluck('number')->all();
});

test('tabs show their counts; urgent first, then oldest; an unknown tab falls back to Open', function () {
    $page = Livewire::actingAs($this->pm)->test(Requests::class);

    expect($page->viewData('counts'))->toBe(['open' => 2, 'in_progress' => 1, 'resolved' => 1, 'all' => 4])
        ->and(($this->numbers)($page))->toBe(['MR-2026-000002', 'MR-2026-000003']);
    $page->set('tab', 'all');
    expect(($this->numbers)($page))->toBe(['MR-2026-000002', 'MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
    $page->set('tab', 'nonsense')->assertOk();
    expect(($this->numbers)($page))->toBe(['MR-2026-000002', 'MR-2026-000003']);
});

test('filters by building, priority, category and vendor; search by number, unit code or tenant', function () {
    $page = Livewire::actingAs($this->pm)->test(Requests::class)->set('tab', 'all');
    $see = fn (array $set, array $numbers) => expect(($this->numbers)(tap($page, fn ($p) => collect($set)->each(fn ($v, $k) => $p->set($k, $v)))))->toBe($numbers);

    $see(['building' => (string) $this->b->id], ['MR-2026-000002']);
    $see(['building' => '', 'priority' => 'urgent'], ['MR-2026-000002']);
    $see(['priority' => '', 'category' => 'plumbing'], ['MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
    $see(['category' => '', 'vendor' => (string) $this->plumber->id], ['MR-2026-000001']);
    $see(['vendor' => '', 'search' => '000003'], ['MR-2026-000003']);
    $see(['search' => '201'], ['MR-2026-000002']);
    $see(['search' => 'Sara'], ['MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
    $see(['search' => '3900 1111'], ['MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
});

test('tampered filters mean no filter, never a 500', function () {
    $page = Livewire::actingAs($this->pm)->test(Requests::class)
        ->set('tab', ['x'])->set('building', ['x'])->set('priority', ['x'])->set('category', 'roofing')->set('vendor', 'abc')->set('search', ['x'])->assertOk();

    expect(($this->numbers)($page))->toBe(['MR-2026-000002', 'MR-2026-000003']);
    Livewire::actingAs($this->pm)->test(WorkOrders::class)->set('status', ['x'])->set('building', 'abc')->set('vendor', ['1'])->assertOk();
});

test('Leasing sees only the requests in its buildings and cannot log one', function () {
    $leasing = matrixUser(R::Leasing, $this->a);
    $page = Livewire::actingAs($leasing)->test(Requests::class)->set('tab', 'all')->assertDontSee('New request');

    expect(($this->numbers)($page))->toBe(['MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004']);
    $page->set('building', (string) $this->b->id);
    expect(($this->numbers)($page))->toBe([]);
    $this->actingAs($leasing)->get(route('maintenance.requests.index'))->assertOk();
    $this->actingAs($leasing)->get(route('maintenance.requests.create'))->assertForbidden();
    $this->actingAs(matrixUser(R::Finance, $this->a))->get(route('maintenance.work-orders.index'))->assertForbidden();
});

test('the work orders list filters by status and vendor and flags overdue work', function () {
    $late = workOrderFor($this->pm, $this->waiting, $this->plumber, ['scheduled_on' => '2026-10-16']);
    workOrderFor($this->pm, $this->urgent, $this->cool, ['scheduled_on' => '2026-10-20']);

    $page = Livewire::actingAs($this->pm)->test(WorkOrders::class)->assertSee('Overdue');
    expect(($this->numbers)($page, 'workOrders'))->toBe(['WO-2026-000002', 'WO-2026-000003', 'WO-2026-000001']); // by scheduled date, unscheduled last

    $page->set('vendor', (string) $this->cool->id)->assertDontSee('Overdue');
    expect(($this->numbers)($page, 'workOrders'))->toBe(['WO-2026-000003']);
    $page->set('vendor', '')->set('status', 'assigned')->set('building', (string) $this->a->id);
    expect(($this->numbers)($page, 'workOrders'))->toBe(['WO-2026-000002', 'WO-2026-000001']);

    $late->forceFill(['status' => 'completed', 'completed_on' => '2026-10-17'])->save();
    expect($late->fresh()->isOverdue())->toBeFalse();
});

test('new request: choosing the unit fills in its tenant, who can be changed or cleared; saving opens the request', function () {
    $visitor = Customer::factory()->create(['name_en' => 'Omar Visitor']);

    $page = Livewire::actingAs($this->pm)->test(RequestForm::class)
        ->set('form.building_id', (string) $this->a->id)->set('form.unit_id', (string) $this->unitA->id)
        ->assertSet('form.customer_id', $this->tenant->id)->assertSee('Sara Tenant')
        ->assertSee('Change')->assertDontSee('Search tenants')
        ->call('$set', 'choosingTenant', true)->assertSee('Search tenants')
        ->set('customerSearch', 'Omar')->assertSee('Omar Visitor')
        ->call('pickTenant', $visitor->id)->assertSet('form.customer_id', $visitor->id)->assertSet('customerSearch', '')
        ->call('clearTenant')->assertSet('form.customer_id', null)
        ->call('pickTenant', $this->tenant->id)
        ->set('form.reported_by_name', 'Sara (tenant)')->set('form.channel', 'whatsapp')->set('form.category', 'plumbing')
        ->set('form.priority', 'high')->set('form.description', 'Water heater leaking')->set('form.entry_permitted', true)
        ->call('save')->assertHasNoErrors();

    $request = MaintenanceRequest::query()->latest('id')->first();
    $page->assertRedirect(route('maintenance.requests.show', $request));
    expect($request->number)->toBe('MR-2026-000005')
        ->and($request->customer_id)->toBe($this->tenant->id)
        ->and($request->priority)->toBe(MaintenancePriority::High)
        ->and($request->channel)->toBe(MaintenanceChannel::Whatsapp)
        ->and($request->entry_permitted)->toBeTrue();
});

test('the new request form refuses a tenant the user cannot see and tampered input, never a 500', function () {
    $scoped = User::factory()->create()->givePermissionTo(['maintenance.view', 'maintenance.manage']);
    $scoped->buildings()->attach($this->a->id);
    $hidden = Customer::factory()->create();
    activeAgreement(['customer_id' => $hidden->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unitB]);

    Livewire::actingAs($scoped)->test(RequestForm::class)
        ->call('pickTenant', $hidden->id)->assertHasErrors('form.customer_id')->assertSet('form.customer_id', null)
        ->call('pickTenant', 'abc')->assertHasErrors('form.customer_id')
        ->call('pickTenant', ['1'])->assertHasErrors('form.customer_id')
        ->set('form.unit_id', (string) $this->unitB->id)->assertSet('form.customer_id', null) // a unit outside the chosen building fills nothing
        ->set('customerSearch', ['x'])->assertOk()
        ->set('form', 'x')->assertOk()->call('save')->assertHasErrors('form.building_id');
    expect(MaintenanceRequest::count())->toBe(4);
});

test('new request: an empty tenant shows the text and Choose, and the search opens only when asked', function () {
    Livewire::actingAs($this->pm)->test(RequestForm::class)
        ->assertSee('No tenant on a current agreement')->assertSee('Choose a tenant')->assertDontSee('Search tenants')
        ->set('choosingTenant', true)->assertSee('Search tenants')
        ->set('choosingTenant', ['x'])->assertOk()->assertDontSee('Search tenants'); // only true opens it
});

test('new request: a tenant who was deleted is not prefilled', function () {
    $unit = Unit::factory()->for($this->a)->create(['code' => '102']);
    $gone = Customer::factory()->create();
    activeAgreement(['customer_id' => $gone->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit]);
    $gone->delete();

    Livewire::actingAs($this->pm)->test(RequestForm::class)
        ->set('form.building_id', (string) $this->a->id)->set('form.unit_id', (string) $unit->id)
        ->assertSet('form.customer_id', null)->assertSee('No tenant on a current agreement');
});

test('each vendor shows its work orders and their total cost, counting only buildings the viewer can see', function () {
    $done = fn ($wo, string $net) => app(CompleteWorkOrder::class)->handle($this->pm, $wo, ['completion_note' => 'Done', 'completed_on' => '2026-10-17',
        'actual_net' => $net, 'actual_tax' => '0', 'charge_to' => 'company', 'category' => 'maintenance', 'description' => 'Repair']);
    $done($this->firstWo, '55'); // building A
    $inB = maintenanceRequest($this->pm, $this->unitB, ['customer_id' => null]);
    $done(workOrderFor($this->pm, $inB, $this->plumber), '20'); // building B

    $row = fn (User $as) => Livewire::actingAs($as)->test(Vendors::class)->viewData('vendors')->firstWhere('name', 'Ali Plumbing');
    expect([$row($this->pm)->work_orders_count, $row($this->pm)->cost])->toBe([2, '75.000']);
    $leasing = matrixUser(R::Leasing, $this->a);
    expect([$row($leasing)->work_orders_count, $row($leasing)->cost])->toBe([1, '55.000']);

    Livewire::actingAs($leasing)->test(VendorForm::class, ['vendor' => $this->plumber])
        ->assertSee($this->firstWo->number)->assertDontSee('MR-2026-000005')->assertSee('Total cost: 55.000 BHD');
});

test('a view-all user does not see requests or work orders of a trashed building; counts match the rows', function () {
    $this->b->delete();
    $admin = matrixUser(R::Admin, $this->a);

    $page = Livewire::actingAs($admin)->test(Requests::class)->set('tab', 'all');
    expect(($this->numbers)($page))->toBe(['MR-2026-000001', 'MR-2026-000003', 'MR-2026-000004'])
        ->and($page->viewData('counts')['all'])->toBe(3);
    expect(MaintenanceRequest::query()->visibleTo($admin)->count())->toBe(3);
    $wo = Livewire::actingAs($admin)->test(WorkOrders::class);
    expect(($this->numbers)($wo, 'workOrders'))->toBe(['WO-2026-000001']);
});

test('a building-scoped user sees only their rows and the counts agree; a LIKE wildcard in search matches nothing extra', function () {
    $leasing = matrixUser(R::Leasing, $this->a);
    $page = Livewire::actingAs($leasing)->test(Requests::class);
    expect($page->viewData('counts'))->toBe(['open' => 1, 'in_progress' => 1, 'resolved' => 1, 'all' => 3]);
    $page->set('tab', 'all')->set('search', '%');
    expect(($this->numbers)($page))->toBe([]);
});
