<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\AddMaintenanceNote;
use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Enums\Trade;
use App\Livewire\Portal\MaintenanceShow;
use App\Livewire\Portal\NewMaintenanceRequest;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->otherUnit = Unit::factory()->for($this->building)->create(['code' => '102']);
    activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->otherUnit]);
    $this->account = portalAccount($this->tenant);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->staffLogged = maintenanceRequest($this->pm, $this->unit); // MR-2026-000001, Sara's, logged by the office
    $this->otherRequest = maintenanceRequest($this->pm, $this->otherUnit); // MR-2026-000002, the other tenant's
    $this->form = fn () => Livewire::actingAs($this->account, 'tenant')->test(NewMaintenanceRequest::class)
        ->set('form.place', 'unit:'.$this->unit->id)->set('form.category', 'ac')->set('form.description', 'The AC is not cooling')->set('form.entry_permitted', true);
});

test('the tenant sends a request with a photo from the portal and lands on its page', function () {
    $page = Livewire::actingAs($this->account, 'tenant')->test(NewMaintenanceRequest::class)
        ->assertSee('Seef Tower 101')->assertSee('Building common area — Seef Tower')->assertDontSee('Seef Tower 102');
    $page->set('form.place', 'unit:'.$this->unit->id)->set('form.category', 'ac')->set('form.description', 'The AC is not cooling')
        ->set('form.entry_permitted', true)->set('photos', [UploadedFile::fake()->image('ac.jpg')])
        ->call('save')->assertHasNoErrors();

    $request = MaintenanceRequest::query()->where('channel', 'portal')->sole();
    $page->assertRedirect(route('portal.maintenance.show', $request));
    expect($request->number)->toBe('MR-2026-000003')
        ->and($request->category)->toBe(Trade::Ac)
        ->and($request->documents()->sole()->original_name)->toBe('ac.jpg');

    $this->actingAs($this->account, 'tenant')->get(route('portal.maintenance.show', $request))->assertOk()
        ->assertSeeInOrder(['MR-2026-000003', 'Open', 'Seef Tower 101', 'Air conditioning', 'The AC is not cooling', 'Request received']);
});

test('tampered form input is a message, never a 500', function (string $property, mixed $value, string $error) {
    ($this->form)()->set($property, $value)->call('save')->assertOk()->assertHasErrors($error);

    expect(MaintenanceRequest::query()->where('channel', 'portal')->count())->toBe(0);
})->with([
    'a place that is not theirs' => ['form.place', 'unit:999999', 'form.place'],
    'place as an array' => ['form.place', ['x'], 'form.place'],
    'the whole form as text' => ['form', 'x', 'form.place'],
    'unknown category' => ['form.category', 'roofing', 'form.category'],
    'description too long' => ['form.description', str_repeat('x', 2001), 'form.description'],
    'entry permission as text' => ['form.entry_permitted', 'maybe', 'form.entry_permitted'],
    'photos as text' => ['photos', 'x', 'photos'],
]);

test('the daily limit is a message on the form', function () {
    foreach (range(1, LogMaintenanceRequest::PORTAL_DAILY_LIMIT) as $i) {
        app(LogMaintenanceRequest::class)->forPortal($this->account, ['place' => 'unit:'.$this->unit->id, 'category' => 'general', 'description' => 'Leak '.$i, 'entry_permitted' => false]);
    }

    ($this->form)()->call('save')->assertOk()->assertHasErrors('request')->assertSee('You have sent 10 requests today.');
});

test('a tenant with no current agreement is asked to call the office', function () {
    $former = Customer::factory()->create(['email' => 'former@tenant.test']);

    Livewire::actingAs(portalAccount($former), 'tenant')->test(NewMaintenanceRequest::class)
        ->assertSee('You have no current agreement, so requests cannot be sent here. Please call the office.')->assertDontSee('Send request');
});

test('the list and the request page show the tenant view: status and dates, never vendors, notes, amounts or work order numbers', function () {
    $workOrder = workOrderFor($this->pm, $this->staffLogged, maintenanceVendor(['name' => 'Gulf Pipes W.L.L.']), ['estimate' => '75.000', 'instructions' => 'Key with the guard']);
    app(AddMaintenanceNote::class)->handle($this->pm, $this->staffLogged, 'Internal: tenant was difficult');
    $this->actingAs($this->account, 'tenant');

    $this->get(route('portal.maintenance'))->assertOk()
        ->assertSeeInOrder(['MR-2026-000001', 'In progress', 'Seef Tower 101', 'Plumbing', '18/10/2026'])
        ->assertDontSee('MR-2026-000002');

    $this->get(route('portal.maintenance.show', $this->staffLogged))->assertOk()
        ->assertSeeInOrder(['MR-2026-000001', 'In progress', 'Kitchen sink leaking', 'Request received', 'Work assigned'])
        ->assertDontSee('Gulf Pipes')->assertDontSee('75.000')->assertDontSee($workOrder->number)
        ->assertDontSee('Internal: tenant was difficult')->assertDontSee('Key with the guard');

    $this->get(route('portal.home'))->assertSee(route('portal.maintenance.show', $this->staffLogged));
});

test('another tenant\'s request is a 404, by URL and by Livewire', function () {
    $this->actingAs($this->account, 'tenant')->get(route('portal.maintenance.show', $this->otherRequest))->assertNotFound();
    $this->get('/portal/maintenance/abc')->assertNotFound();

    Livewire::actingAs($this->account, 'tenant')->test(MaintenanceShow::class, ['maintenance' => (string) $this->otherRequest->id])->assertNotFound();
});

test('ids that are not this tenant\'s are a 404: huge, non-numeric, missing', function () {
    $this->actingAs($this->account, 'tenant');
    $this->get('/portal/maintenance/'.str_repeat('9', 30))->assertNotFound();
    $this->get('/portal/maintenance/999999')->assertNotFound();
    $this->get('/portal/maintenance/-1')->assertNotFound();
    $this->get(route('portal.maintenance.show', $this->staffLogged))->assertOk();
});

test('a place whose building is soft-deleted is not offered and the form still loads', function () {
    $gone = Building::factory()->create(['name' => 'Gone Tower']);
    $goneUnit = Unit::factory()->for($gone)->create(['code' => '9']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$goneUnit]);
    $gone->delete();

    expect(LogMaintenanceRequest::portalPlaces($this->tenant->id))->not->toHaveKey('unit:'.$goneUnit->id)->not->toHaveKey('building:'.$gone->id);
    Livewire::actingAs($this->account, 'tenant')->test(NewMaintenanceRequest::class)->assertOk()->assertSee('Seef Tower 101')->assertDontSee('Gone Tower');
});

test('the portal pages need the tenant guard and the form exposes only save', function () {
    $this->get(route('portal.maintenance'))->assertRedirect();
    $this->get(route('portal.maintenance.create'))->assertRedirect();
    $public = collect((new ReflectionClass(NewMaintenanceRequest::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn ($m) => ! $m->isStatic() && ! str_starts_with($m->name, '_') && $m->class === NewMaintenanceRequest::class)->map->getName()->all();
    expect($public)->toEqualCanonicalizing(['save', 'render']);
});

test('bad photos are a message, never a 500', function () {
    ($this->form)()->set('photos', [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->call('save')->assertOk()->assertHasErrors('photos.0');
    ($this->form)()->set('photos', array_map(fn ($i) => UploadedFile::fake()->image("p$i.jpg"), range(1, 6)))->call('save')->assertOk()->assertHasErrors('photos');
});
