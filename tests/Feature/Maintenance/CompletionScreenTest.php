<?php

use App\Actions\EnsureNumberSequences;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Enums\WorkOrderStatus;
use App\Livewire\Maintenance\RequestShow;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
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
    CompanySetting::factory()->create(['vat_registered' => true, 'vat_rate' => '10.00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'mobile' => '+973 3900 1111']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    activeOwnerContract(['building_id' => $this->building->id, 'type' => 'managed', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'expense_approval_limit' => '200.000'], [$this->unit]);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->plumber = maintenanceVendor(['name' => 'Ali Plumbing', 'trades' => ['plumbing']]);
    $this->request = maintenanceRequest($this->pm, $this->unit);
    $this->wo = workOrderFor($this->pm, $this->request, $this->plumber, ['scheduled_on' => '2026-10-19', 'instructions' => 'Bring a new trap', 'estimate' => '25.500']);
    $this->page = fn (User $as, ?MaintenanceRequest $request = null) => Livewire::actingAs($as)->test(RequestShow::class, ['maintenanceRequest' => $request ?? $this->request]);
});

test('opening the form prefills the expense from the work order, and VAT follows the net', function () {
    $cleaning = maintenanceRequest($this->pm, $this->unit, ['category' => 'cleaning', 'description' => 'Deep clean after move-out']);
    $wo = workOrderFor($this->pm, $cleaning, maintenanceVendor(['name' => 'Shine Co', 'trades' => ['cleaning']]));

    ($this->page)($this->pm, $cleaning)->call('openCompletion', $wo->id)
        ->assertSet('completion.work_order_id', $wo->id)
        ->assertSet('completion.completed_on', '2026-10-17')
        ->assertSet('completion.category', 'cleaning')
        ->assertSet('completion.description', $wo->number.' · Shine Co · Deep clean after move-out')
        ->assertSet('completion.charge_to', 'company')
        ->set('completion.actual_net', '120')->assertSet('completion.actual_tax', '12.000')
        ->assertSee('Record expense')
        ->assertDontSeeHtml('value="tenant"'); // a property manager has no invoices.manage, so no tenant charge
});

test('the category prefill for a non-cleaning trade is maintenance', function () {
    ($this->page)($this->pm)->call('openCompletion', $this->wo->id)->assertSet('completion.category', 'maintenance');
});

test('completing with a cost from the page records the expense and resolves the request', function () {
    ($this->page)($this->pm)->call('openCompletion', $this->wo->id)
        ->set('completion.completion_note', 'Replaced the trap')->set('completion.actual_net', '50')
        ->call('complete')->assertHasNoErrors()
        ->assertSee('Resolved')->assertSee('50.000 + 5.000 VAT');

    expect($this->wo->fresh()->expense->total)->toBe('55.000')->and($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved);
});

test('above the owner limit the form asks for the approval; with the note and the document it is recorded', function () {
    $page = ($this->page)($this->pm)->call('openCompletion', $this->wo->id)
        ->set('completion.completion_note', 'New water heater')->set('completion.actual_net', '300')->set('completion.charge_to', 'owner')
        ->assertSee('Owner approval document')
        ->call('complete')->assertHasErrors('completion.owner_approval_note');
    expect($this->wo->fresh()->status)->toBe(WorkOrderStatus::Assigned)->and(Expense::count())->toBe(0);

    $page->set('completion.owner_approval_note', 'Owner approved by email')
        ->set('ownerApproval', UploadedFile::fake()->create('approval.pdf', 20, 'application/pdf'))
        ->call('complete')->assertHasNoErrors();
    expect($this->wo->fresh()->expense->owner_contract_id)->not->toBeNull()->and($this->wo->fresh()->expense->total)->toBe('330.000');
});

test('Admin is told a cost needs expenses.manage, and can complete with no cost', function () {
    $page = ($this->page)(matrixUser(R::Admin, $this->building))->call('openCompletion', $this->wo->id)
        ->set('completion.completion_note', 'Done under warranty')->set('completion.actual_net', '50')
        ->assertSee('Recording a cost needs expenses.manage.')
        ->call('complete')->assertHasErrors('completion.charge_to');
    expect($this->wo->fresh()->status)->toBe(WorkOrderStatus::Assigned);

    $page->set('completion.actual_net', '')->set('completion.actual_tax', '0')->call('complete')->assertHasNoErrors();
    expect($this->wo->fresh()->status)->toBe(WorkOrderStatus::Completed)->and($this->wo->fresh()->expense_id)->toBeNull();
});

test('tampered completion input is a message, never a 500', function (Closure $tamper, string $error) {
    $this->otherWo = workOrderFor($this->pm, maintenanceRequest($this->pm, $this->unit), $this->plumber);

    $tamper->call($this, ($this->page)($this->pm))->assertOk()->assertHasErrors($error);
    expect($this->otherWo->fresh()->status)->toBe(WorkOrderStatus::Assigned)->and($this->wo->fresh()->status)->toBe(WorkOrderStatus::Assigned);
})->with([
    'the form as text' => [fn ($page) => $page->set('completion', 'x')->call('complete'), 'completion.completion_note'],
    'another request\'s work order' => [fn ($page) => $page->call('openCompletion', $this->wo->id)->set('completion.work_order_id', $this->otherWo->id)->call('complete'), 'completion.completion_note'],
    'open another request\'s work order' => [fn ($page) => $page->call('openCompletion', $this->otherWo->id), 'workOrders'],
    'a payer as an array' => [fn ($page) => $page->call('openCompletion', $this->wo->id)->set('completion.completion_note', 'x')->set('completion.actual_net', '5')
        ->set('completion.charge_to', ['owner'])->call('complete'), 'completion.charge_to'],
    'a net as an array' => [fn ($page) => $page->call('openCompletion', $this->wo->id)->set('completion.completion_note', 'x')->set('completion.actual_net', ['5'])->call('complete'), 'completion.actual_net'],
]);

test('the work order sheet and the WhatsApp text carry what the vendor needs, and no amounts', function () {
    $this->actingAs($this->pm)->get(route('maintenance.work-orders.sheet', $this->wo))->assertOk()
        ->assertSee($this->wo->number)->assertSee('Seef Tower')->assertSee('101')->assertSee('Sara Tenant · +973 3900 1111')
        ->assertSee('Bring a new trap')->assertSee('19/10/2026')->assertSee('Kitchen sink leaking')->assertSee('Entry permitted')
        ->assertDontSee('25.500')->assertDontSee('BHD');

    expect($this->wo->sheetText())
        ->toContain('Tenant: Sara Tenant · +973 3900 1111')
        ->toContain('Entry permitted: Yes')
        ->toContain('Scheduled: 19/10/2026')
        ->toContain('Instructions: Bring a new trap')
        ->not->toContain('25.5')->not->toContain('BHD');
    ($this->page)($this->pm)->assertSee('Copy for WhatsApp')->assertSee(route('maintenance.work-orders.sheet', $this->wo));
});

test('the sheet escapes what staff typed', function () {
    $this->wo->update(['instructions' => '<script>alert(1)</script>']);

    $this->actingAs($this->pm)->get(route('maintenance.work-orders.sheet', $this->wo))->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
});

test('the sheet needs maintenance.view in the request\'s building', function () {
    $this->actingAs(matrixUser(R::Leasing, $this->building))->get(route('maintenance.work-orders.sheet', $this->wo))->assertOk();
    $this->actingAs(matrixUser(R::Finance, $this->building))->get(route('maintenance.work-orders.sheet', $this->wo))->assertForbidden();

    $elsewhere = User::factory()->create()->givePermissionTo('maintenance.view');
    $elsewhere->buildings()->attach(Building::factory()->create()->id);
    $this->actingAs($elsewhere)->get(route('maintenance.work-orders.sheet', $this->wo))->assertForbidden();
});

test('a trashed vendor or unit never breaks the sheet or the request page; a trashed building is out of scope, a 403 and not a 500', function () {
    $this->plumber->delete();
    $this->unit->delete();

    $this->actingAs($this->pm)->get(route('maintenance.work-orders.sheet', $this->wo))->assertOk()
        ->assertSee('Ali Plumbing')->assertSee('Seef Tower')->assertSee('101');
    ($this->page)($this->pm)->assertOk()->assertSee('Copy for WhatsApp');

    $this->building->delete();
    $this->actingAs($this->pm)->get(route('maintenance.work-orders.sheet', $this->wo))->assertForbidden();
});

test('a user who can only view cannot open or submit the completion form', function () {
    $leasing = matrixUser(R::Leasing, $this->building);

    ($this->page)($leasing)->call('openCompletion', $this->wo->id)->assertForbidden();
    ($this->page)($leasing)->call('complete')->assertForbidden();
});
