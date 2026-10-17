<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Livewire\Documents\Panel;
use App\Livewire\Maintenance\RequestShow;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
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
    $this->request = maintenanceRequest($this->pm, $this->unit);
    $this->page = fn (User $as) => Livewire::actingAs($as)->test(RequestShow::class, ['maintenanceRequest' => $this->request]);
});

test('the page opens for maintenance.view in the request\'s building only', function () {
    foreach ([R::Admin, R::Management, R::PropertyManager, R::Leasing, R::VendorSupport] as $role) {
        $this->actingAs(matrixUser($role, $this->building))->get(route('maintenance.requests.show', $this->request))
            ->assertOk()->assertSee('MR-2026-000001')->assertSee('Sara Tenant')->assertSee('Kitchen sink leaking');
    }
    $this->actingAs(matrixUser(R::Finance, $this->building))->get(route('maintenance.requests.show', $this->request))->assertForbidden();

    $elsewhere = User::factory()->create()->givePermissionTo('maintenance.view');
    $elsewhere->buildings()->attach(Building::factory()->create()->id);
    $this->actingAs($elsewhere)->get(route('maintenance.requests.show', $this->request))->assertForbidden();
});

test('a property manager adds a work order; only active vendors doing the trade, or general work, are offered', function () {
    maintenanceVendor(['name' => 'Gulf Cool', 'trades' => ['ac']]);
    maintenanceVendor(['name' => 'Retired Plumber', 'trades' => ['plumbing'], 'active' => false]);
    maintenanceVendor(['name' => 'Our Handyman', 'trades' => ['general'], 'type' => 'own_staff']);

    $page = ($this->page)($this->pm)
        ->assertSee('Ali Plumbing')->assertSee('Our Handyman')->assertDontSee('Gulf Cool')->assertDontSee('Retired Plumber')
        ->set('workOrder.vendor_id', (string) $this->plumber->id)->set('workOrder.scheduled_on', '2026-10-19')->set('workOrder.estimate', '30')
        ->call('addWorkOrder')->assertHasNoErrors();

    expect($this->request->workOrders()->sole()->estimate)->toBe('30.000');
    $page->assertSee('WO-2026-000001')->assertSee('19/10/2026')->assertSee('In progress');
});

test('start, cancel with a reason, a note and resolve, each on the timeline', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->plumber);
    $page = ($this->page)($this->pm);

    $page->call('startWorkOrder', $wo->id)->assertHasNoErrors();
    expect($wo->fresh()->status)->toBe(WorkOrderStatus::InProgress);

    $page->call('openCancel', $wo->id)->call('cancelWorkOrder')->assertHasErrors('cancelling.reason');
    $page->set('cancelling.reason', 'Vendor unavailable')->call('cancelWorkOrder')->assertHasNoErrors();
    expect($wo->fresh()->status)->toBe(WorkOrderStatus::Cancelled);

    $page->set('note', 'Called the tenant to rebook.')->call('addNote')->assertHasNoErrors()
        ->assertSee('Called the tenant to rebook.')->assertSee($wo->number.': In progress → Cancelled · Vendor unavailable');

    $page->call('resolve')->assertHasNoErrors()->assertSee('In progress → Resolved')->assertDontSee('Add work order');
    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved);
});

test('resolving with an open work order is a message; cancelling the request needs a reason', function () {
    workOrderFor($this->pm, $this->request, $this->plumber);
    $page = ($this->page)($this->pm);

    $page->call('resolve')->assertHasErrors('request');
    $page->call('cancelRequest')->assertHasErrors('cancelReason');
    $page->set('cancelReason', 'Duplicate')->call('cancelRequest')->assertHasNoErrors()->assertSee('Duplicate');
    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::Cancelled);
});

test('Leasing sees the page without any action, and a call from the browser is refused', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->plumber);

    ($this->page)(matrixUser(R::Leasing, $this->building))
        ->assertSee($wo->number)->assertDontSee('Add work order')->assertDontSee('Add note')->assertDontSee('Cancel request')
        ->call('startWorkOrder', $wo->id)->assertForbidden();
    ($this->page)(matrixUser(R::Leasing, $this->building))->call('openCancel', $wo->id)->assertForbidden();
    expect($wo->fresh()->status)->toBe(WorkOrderStatus::Assigned);
});

test('tampered input is a message, never a 500', function (Closure $tamper, string $error) {
    $this->otherWo = workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unit), $this->plumber);

    $tamper->call($this, ($this->page)($this->pm))->assertOk()->assertHasErrors($error);
    expect($this->otherWo->fresh()->status)->toBe(WorkOrderStatus::Assigned);
})->with([
    'start a work order of another request' => [fn ($page) => $page->call('startWorkOrder', $this->otherWo->id), 'workOrders'],
    'a work order id as text' => [fn ($page) => $page->call('startWorkOrder', 'abc'), 'workOrders'],
    'a work order id as an array' => [fn ($page) => $page->call('openCancel', ['1']), 'workOrders'],
    'the cancel form as text' => [fn ($page) => $page->set('cancelling', 'x')->call('cancelWorkOrder'), 'cancelling.reason'],
    'cancel another request\'s work order' => [fn ($page) => $page->set('cancelling', ['work_order_id' => $this->otherWo->id, 'reason' => 'x'])->call('cancelWorkOrder'), 'cancelling.reason'],
    'the work order form as text' => [fn ($page) => $page->set('workOrder', 'x')->call('addWorkOrder'), 'workOrder.vendor_id'],
    'an inactive vendor' => [fn ($page) => $page->set('workOrder.vendor_id', (string) maintenanceVendor(['trades' => ['plumbing'], 'active' => false])->id)->call('addWorkOrder'), 'workOrder.vendor_id'],
    'a note as an array' => [fn ($page) => $page->set('note', ['x'])->call('addNote'), 'note'],
    'start with no argument' => [fn ($page) => $page->call('startWorkOrder'), 'workOrders'],
    'open cancel with no argument' => [fn ($page) => $page->call('openCancel'), 'workOrders'],
    'a cancel reason as an array' => [fn ($page) => $page->set('cancelReason', ['x'])->call('cancelRequest'), 'cancelReason'],
]);

test('photos: maintenance photos on the request and on a work order; Leasing only sees them', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->plumber);

    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $this->request])->assertSet('category', 'maintenance')
        ->set('upload', UploadedFile::fake()->image('leak.jpg'))->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->pm)->test(Panel::class, ['documentable' => $wo])
        ->set('upload', UploadedFile::fake()->image('fixed.jpg'))->call('save')->assertHasNoErrors();

    expect($this->request->documents()->sole()->category)->toBe(DocumentCategory::Maintenance)
        ->and($wo->documents()->sole()->original_name)->toBe('fixed.jpg');
    Livewire::actingAs(matrixUser(R::Leasing, $this->building))->test(Panel::class, ['documentable' => $this->request])
        ->assertSee('leak.jpg')->assertDontSee('Upload');
});

test('linked expenses and their total show to those who may see expenses', function () {
    $wo = workOrderFor($this->pm, $this->request, $this->plumber);
    app(CompleteWorkOrder::class)->handle($this->pm, $wo, ['completion_note' => 'Done', 'completed_on' => '2026-10-17', 'actual_net' => '40',
        'actual_tax' => '0', 'charge_to' => 'company', 'category' => 'maintenance', 'description' => 'Sink repair']);

    ($this->page)($this->pm)->assertSee('Sink repair')->assertSee('Total: 40.000 BHD');
    ($this->page)(matrixUser(R::Leasing, $this->building))->assertDontSee('Total: 40.000 BHD');
});
