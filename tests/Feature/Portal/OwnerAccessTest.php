<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Maintenance\LogMaintenanceRequest;
use App\Enums\RoleName as R;
use App\Livewire\Portal;
use App\Mail\PortalSignInCode;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->owner = Owner::factory()->create(['name_en' => 'Ali Owner', 'name_ar' => 'علي', 'email' => 'ali@owner.test']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    $this->signIn = fn (string $email) => Livewire::test(Portal\Login::class)->set('email', $email)->call('sendCode')
        ->set('code', portalCodeFor($email))->call('signIn');
});

test('sign-in lands a tenant-only account on the tenant home', function () {
    portalAccount($this->tenant);

    ($this->signIn)('sara@tenant.test')->assertRedirect(route('portal.home'));
});

test('sign-in lands an owner-only account on the owner home, and the code email greets the owner', function () {
    portalAccount($this->owner);

    ($this->signIn)('ali@owner.test')->assertRedirect(route('portal.owner.home'));

    $mail = Mail::queued(PortalSignInCode::class)->sole();
    expect($mail->nameEn)->toBe('Ali Owner')->and($mail->nameAr)->toBe('علي');
});

test('a shared account lands on the tenant home, switches with the header links, and the last side is kept in the session', function () {
    $account = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);
    ($this->signIn)('sara@tenant.test')->assertRedirect(route('portal.home'));

    $this->actingAs($account, 'tenant');
    $this->get(route('portal.home'))->assertOk()->assertSee('Welcome, Sara Tenant')
        ->assertSee(route('portal.owner.home'))->assertSee(route('portal.agreements'));
    $this->get(route('portal.owner.home'))->assertOk()->assertSessionHas('portal_view', 'owner')
        ->assertSee('Welcome, Ali Owner')->assertDontSee(route('portal.agreements'));
    $this->get(route('portal.login'))->assertRedirect(route('portal.owner.home'));

    $this->get(route('portal.home'))->assertOk()->assertSessionHas('portal_view', 'tenant');
    $this->get(route('portal.login'))->assertRedirect(route('portal.home'));
});

test('a tenant-only account sees no switch and gets a 404 on the owner pages', function () {
    $account = portalAccount($this->tenant);

    $this->actingAs($account, 'tenant')->get(route('portal.home'))->assertOk()->assertDontSee(route('portal.owner.home'));
    $this->get(route('portal.owner.home'))->assertNotFound();
    Livewire::actingAs($account, 'tenant')->test(Portal\Owner\Home::class)->assertNotFound();
});

test('an owner-only account opening /portal goes to the owner home; tenant pages, downloads and requests are a 404', function () {
    $account = portalAccount($this->owner);
    $building = Building::factory()->create();
    $pm = matrixUser(R::PropertyManager, $building);
    // A common-area request has no tenant: a null customer_id must never match an owner-only account.
    $commonArea = maintenanceRequest($pm, Unit::factory()->for($building)->create(), ['unit_id' => null, 'customer_id' => null]);

    $this->actingAs($account, 'tenant');
    $this->get(route('portal.home'))->assertRedirect(route('portal.owner.home'));
    foreach ([
        route('portal.agreements'), route('portal.invoices'), route('portal.payments'), route('portal.cheques'), route('portal.statement'),
        route('portal.documents'), route('portal.maintenance'), route('portal.maintenance.create'), route('portal.maintenance.show', $commonArea),
        route('portal.statement.pdf', ['from' => '2026-01-01', 'to' => '2026-10-21']),
    ] as $url) {
        $this->get($url)->assertNotFound();
    }

    foreach ([Portal\Home::class, Portal\Agreements::class, Portal\Statement::class, Portal\Maintenance::class, Portal\NewMaintenanceRequest::class] as $component) {
        Livewire::actingAs($account, 'tenant')->test($component)->assertNotFound();
    }
    Livewire::actingAs($account, 'tenant')->test(Portal\MaintenanceShow::class, ['maintenance' => (string) $commonArea->id])->assertNotFound();
    expect(fn () => app(LogMaintenanceRequest::class)->forPortal($account, ['place' => 'building:'.$building->id, 'category' => 'general', 'description' => 'Leak', 'entry_permitted' => false]))
        ->toThrow(AuthorizationException::class);
});

test('a deleted owner loses the owner side; with no active record left the session ends', function () {
    $shared = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);
    $this->actingAs($shared, 'tenant')->get(route('portal.owner.home'))->assertOk();
    $this->owner->delete();

    $this->get(route('portal.owner.home'))->assertNotFound();
    $this->get(route('portal.home'))->assertOk()->assertDontSee(route('portal.owner.home'));
    $this->get(route('portal.login'))->assertRedirect(route('portal.home'));

    $this->tenant->delete();
    $this->get(route('portal.home'))->assertRedirect(route('portal.login'))->assertSessionHas('status');
    expect(Auth::guard('tenant')->check())->toBeFalse();
});

test('an owner-only account whose owner is deleted is signed out on the next request', function () {
    $this->actingAs(portalAccount($this->owner), 'tenant')->get(route('portal.owner.home'))->assertOk();
    $this->owner->delete();

    $this->get(route('portal.owner.home'))->assertRedirect(route('portal.login'));
    expect(Auth::guard('tenant')->check())->toBeFalse();
});

test('the owner side speaks Arabic, right to left, for an Arabic account', function () {
    $this->actingAs(portalAccount($this->owner, ['locale' => 'ar']), 'tenant')->get(route('portal.owner.home'))
        ->assertOk()->assertSee('dir="rtl"', false)->assertSee('أهلاً، علي')->assertSee('بوابة المالك');
});
