<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\AddMaintenanceNote;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\RoleName as R;
use App\Livewire\Maintenance\Requests;
use App\Mail\MaintenanceUpdate;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    // Sara's ended agreement elsewhere, and another tenant's unit next door: neither may be chosen.
    $this->oldUnit = Unit::factory()->for(Building::factory()->create(['name' => 'Juffair Heights']))->create(['code' => '7']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31'], [$this->oldUnit]);
    $this->otherUnit = Unit::factory()->for($this->building)->create(['code' => '102']);
    activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->otherUnit]);
    $this->account = portalAccount($this->tenant);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->log = fn (array $data = [], mixed $photos = []) => app(LogMaintenanceRequest::class)->forPortal($this->account, [
        'place' => 'unit:'.$this->unit->id, 'category' => 'plumbing', 'description' => 'Kitchen sink leaking', 'entry_permitted' => true, ...$data,
    ], $photos);
});

test('the tenant may choose their units on a current agreement, or the common area of those buildings', function () {
    expect(LogMaintenanceRequest::portalPlaces($this->tenant->id))->toBe([
        'unit:'.$this->unit->id => 'Seef Tower 101',
        'building:'.$this->building->id => 'Building common area — Seef Tower',
    ]);
});

test('a portal request goes through the one write path: portal channel, the tenant, normal priority, photos, timeline, email, audit', function () {
    $request = ($this->log)([], [UploadedFile::fake()->image('leak.jpg'), UploadedFile::fake()->image('pipe.png')]);

    expect($request->number)->toBe('MR-2026-000001')
        ->and($request->channel)->toBe(MaintenanceChannel::Portal)
        ->and($request->customer_id)->toBe($this->tenant->id)
        ->and($request->reported_by_name)->toBe('Sara Tenant')
        ->and($request->priority)->toBe(MaintenancePriority::Normal)
        ->and($request->status)->toBe(MaintenanceStatus::Open)
        ->and($request->unit_id)->toBe($this->unit->id)
        ->and($request->building_id)->toBe($this->building->id)
        ->and($request->entry_permitted)->toBeTrue()
        ->and($request->created_by)->toBeNull()
        ->and($request->portal_account_id)->toBe($this->account->id);

    $event = $request->events()->sole();
    expect($event->to_status)->toBe('open')->and($event->user_id)->toBeNull()->and($event->portal_account_id)->toBe($this->account->id);

    $photos = $request->documents()->orderBy('id')->get();
    expect($photos->pluck('original_name')->all())->toBe(['leak.jpg', 'pipe.png'])
        ->and($photos->every(fn ($d) => $d->category === DocumentCategory::Maintenance && $d->portal_account_id === $this->account->id
            && $d->uploaded_by === null && $d->shared_with_tenant === false))->toBeTrue();

    Mail::assertQueued(MaintenanceUpdate::class, fn (MaintenanceUpdate $m) => $m->request->is($request) && $m->kind === MaintenanceUpdate::RECEIVED
        && $m->hasTo('sara@tenant.test') && $m->locale === 'en');
    expect(Activity::query()->where('event', 'portal.maintenance_requested')->sole()->causer?->is($this->account))->toBeTrue();
});

test('a common-area request has the building and no unit', function () {
    $request = ($this->log)(['place' => 'building:'.$this->building->id]);

    expect($request->unit_id)->toBeNull()->and($request->building_id)->toBe($this->building->id)->and($request->place())->toBe('Seef Tower');
});

test('anything outside the tenant\'s current places, and bad input, is a validation message', function (Closure $data, string $key) {
    try {
        ($this->log)($data->call($this));
        $this->fail('The request was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
    expect(MaintenanceRequest::count())->toBe(0);
})->with([
    'another tenant\'s unit' => [fn () => ['place' => 'unit:'.$this->otherUnit->id], 'place'],
    'a unit of an ended agreement' => [fn () => ['place' => 'unit:'.$this->oldUnit->id], 'place'],
    'the ended agreement\'s building' => [fn () => ['place' => 'building:'.$this->oldUnit->building_id], 'place'],
    'a made-up place' => [fn () => ['place' => 'unit:999999'], 'place'],
    'a bare unit id' => [fn () => ['place' => (string) $this->unit->id], 'place'],
    'place as an array' => [fn () => ['place' => ['unit:'.$this->unit->id]], 'place'],
    'no place' => [fn () => ['place' => ''], 'place'],
    'unknown category' => [fn () => ['category' => 'roofing'], 'category'],
    'no description' => [fn () => ['description' => ''], 'description'],
    'description too long' => [fn () => ['description' => str_repeat('x', 2001)], 'description'],
    'entry permission not a boolean' => [fn () => ['entry_permitted' => 'maybe'], 'entry_permitted'],
]);

test('photos: up to 5 pictures, jpg, png or webp, 10 MB each', function (Closure $photos, string $key) {
    try {
        ($this->log)([], $photos());
        $this->fail('The request was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
    expect(MaintenanceRequest::count())->toBe(0);
})->with([
    'six photos' => [fn () => array_map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 6)), 'photos'],
    'a PDF' => [fn () => [UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf')], 'photos.0'],
    'over 10 MB' => [fn () => [UploadedFile::fake()->image('big.jpg')->size(10241)], 'photos.0'],
    'not a file' => [fn () => ['x'], 'photos.0'],
    'photos as text' => [fn () => 'x', 'photos'],
]);

test('10 portal requests per account per day', function () {
    foreach (range(1, 10) as $i) {
        ($this->log)();
    }
    expect(fn () => ($this->log)())->toThrow(ValidationException::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-19 00:01', 'Asia/Bahrain'));
    ($this->log)();
    expect(MaintenanceRequest::count())->toBe(11);
});

test('a disabled account or a deleted tenant cannot send a request', function (Closure $end) {
    $end->call($this);

    expect(fn () => ($this->log)())->toThrow(AuthorizationException::class)
        ->and(MaintenanceRequest::count())->toBe(0);
})->with([
    'disabled' => [fn () => $this->account->forceFill(['disabled_at' => now()])->save()],
    'tenant deleted' => [fn () => $this->tenant->delete()],
]);

test('the tenant timeline shows received, work assigned and completed; never vendors, notes or amounts', function () {
    $request = ($this->log)();
    $workOrder = workOrderFor($this->pm, $request, maintenanceVendor(['name' => 'Gulf Pipes W.L.L.']), ['estimate' => '75.000', 'instructions' => 'Key with the guard']);
    app(AddMaintenanceNote::class)->handle($this->pm, $request, 'Internal: tenant was difficult');
    app(CompleteWorkOrder::class)->handle($this->pm, $workOrder, ['completion_note' => 'Replaced the trap', 'completed_on' => '2026-10-18', 'actual_net' => '']);

    $timeline = $request->fresh()->tenantTimeline();
    expect(array_column($timeline, 'label'))->toBe(['Request received', 'Work assigned', 'Completed'])
        ->and(json_encode($timeline))->not->toContain('Gulf Pipes')->not->toContain('75')->not->toContain('Internal')->not->toContain('WO-');
});

test('a cancelled request ends with "Cancelled" on the tenant timeline', function () {
    $request = ($this->log)();
    app(CancelMaintenanceRequest::class)->handle($this->pm, $request, 'Logged twice');

    expect(array_column($request->fresh()->tenantTimeline(), 'label'))->toBe(['Request received', 'Cancelled']);
});

test('staff see a Portal badge on the list and the request page, and the portal as who logged it', function () {
    $request = ($this->log)();

    Livewire::actingAs($this->pm)->test(Requests::class)->assertSee('MR-2026-000001')->assertSee('Portal');
    $this->actingAs($this->pm)->get(route('maintenance.requests.show', $request))->assertOk()
        ->assertSee('Portal')->assertSeeInOrder(['Logged', '18/10/2026', 'Tenant portal']);
});
