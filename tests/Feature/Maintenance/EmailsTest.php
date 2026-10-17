<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Actions\Maintenance\ChangeWorkOrderStatus;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Mail\MaintenanceUpdate;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create(['name' => 'Seef Tower']);
    $this->unit = Unit::factory()->for($this->building)->create(['code' => '101']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'sara@tenant.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->vendor = maintenanceVendor();
    $this->finish = fn ($wo) => app(CompleteWorkOrder::class)->handle($this->pm, $wo, ['completion_note' => 'Done', 'completed_on' => '2026-10-17', 'actual_net' => '']);
});

test('logging a request queues one bilingual "request received" email to the tenant, with no amounts', function () {
    $request = maintenanceRequest($this->pm, $this->unit);

    Mail::assertQueued(MaintenanceUpdate::class, 1);
    Mail::assertQueued(MaintenanceUpdate::class, fn (MaintenanceUpdate $m) => $m->hasTo('sara@tenant.test', 'Sara Tenant')
        && $m->kind === MaintenanceUpdate::RECEIVED && $m->request->is($request));

    $mail = new MaintenanceUpdate($request, MaintenanceUpdate::RECEIVED);
    $mail->assertHasSubject('Maintenance request received · تم استلام طلب الصيانة · Demo Properties W.L.L.')
        ->assertFrom('no-reply@rms.test', 'Demo Properties W.L.L.')
        ->assertHasReplyTo('office@demo.test')
        ->assertSeeInOrderInHtml([
            'dir="ltr"', 'Dear Sara Tenant,', 'We have received your maintenance request MR-2026-000001 for Seef Tower 101 on 17/10/2026.', 'Kitchen sink leaking',
            'dir="rtl"', 'عزيزي المستأجر سارة،', 'تم استلام طلب الصيانة رقم MR-2026-000001 الخاص بـ Seef Tower 101 بتاريخ 17/10/2026.',
            'Demo Properties W.L.L.',
        ], false)
        ->assertDontSeeInHtml('BHD');
    expect($mail->tries)->toBe(1)->and($mail)->toBeInstanceOf(ShouldQueue::class);
});

test('resolving, automatically or by hand, queues one "request completed" email; cancelling sends none', function () {
    $auto = maintenanceRequest($this->pm, $this->unit);
    ($this->finish)(workOrderFor($this->pm, $auto, $this->vendor));
    $manual = maintenanceRequest($this->pm, $this->unit);
    app(ResolveMaintenanceRequest::class)->handle($this->pm, $manual);
    app(CancelMaintenanceRequest::class)->handle($this->pm, maintenanceRequest($this->pm, $this->unit), 'Logged twice');

    $completed = fn ($request) => fn (MaintenanceUpdate $m) => $m->kind === MaintenanceUpdate::COMPLETED && $m->request->is($request);
    Mail::assertQueued(MaintenanceUpdate::class, 5); // three received, two completed
    Mail::assertQueued(MaintenanceUpdate::class, $completed($auto));
    Mail::assertQueued(MaintenanceUpdate::class, $completed($manual));

    (new MaintenanceUpdate($auto->fresh(), MaintenanceUpdate::COMPLETED))
        ->assertHasSubject('Maintenance request completed · تم إنجاز طلب الصيانة · Demo Properties W.L.L.')
        ->assertSeeInOrderInHtml(['Your maintenance request MR-2026-000001 for Seef Tower 101 was completed on 17/10/2026.', 'dir="rtl"',
            'تم إنجاز طلب الصيانة رقم MR-2026-000001 الخاص بـ Seef Tower 101 بتاريخ 17/10/2026.'], false);
});

test('no email for a tenant with no address, one who opted out of reminders, a common area, or while mail is not set up', function (Closure $setUp) {
    $setUp->call($this);
    $request = maintenanceRequest($this->pm, $this->unit, $this->data ?? []);
    ($this->finish)(workOrderFor($this->pm, $request, $this->vendor));

    Mail::assertNothingQueued();
})->with([
    'no email' => [fn () => $this->tenant->update(['email' => null])],
    'opted out' => [fn () => $this->tenant->update(['no_reminders' => true])],
    'a common area' => [fn () => $this->data = ['unit_id' => null, 'customer_id' => null]],
    'mail not set up' => [fn () => config(['mail.default' => 'log'])],
]);

test('a refused request sends nothing, and a work order change that does not resolve sends nothing', function () {
    $request = maintenanceRequest($this->pm, $this->unit);
    $wo = workOrderFor($this->pm, $request, $this->vendor);
    app(ChangeWorkOrderStatus::class)->handle($this->pm, $wo, 'in_progress');
    app(ChangeWorkOrderStatus::class)->handle($this->pm, $wo, 'cancelled', 'Vendor did not come'); // none completed: still in progress

    try {
        maintenanceRequest($this->pm, $this->unit, ['description' => '']);
    } catch (ValidationException) {
        // refused before anything was saved
    }
    Mail::assertQueued(MaintenanceUpdate::class, 1); // only the first request's "received"
});

test('the email is queued after commit, so a rolled-back request never emails (Mail::fake ignores transactions, so the flag is asserted)', function () {
    maintenanceRequest($this->pm, $this->unit);

    Mail::assertQueued(MaintenanceUpdate::class, fn (MaintenanceUpdate $m) => $m->afterCommit === true);
});

test('a tenant deleted after the request was logged is not emailed when it is completed', function () {
    $request = maintenanceRequest($this->pm, $this->unit);
    $this->tenant->delete();
    ($this->finish)(workOrderFor($this->pm, $request, $this->vendor));

    Mail::assertNotQueued(MaintenanceUpdate::class, fn (MaintenanceUpdate $m) => $m->kind === MaintenanceUpdate::COMPLETED);
});
