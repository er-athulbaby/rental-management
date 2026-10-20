<?php

use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Enums\DocumentCategory;
use App\Enums\MaintenanceChannel;
use App\Enums\RoleName as R;
use App\Livewire\Maintenance\RequestForm;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->unit = Unit::factory()->for($this->building)->create();
    $this->pm = matrixUser(R::PropertyManager, $this->building);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'Sara@Tenant.test']);
});

test('the tenant guard signs in portal accounts, remembers for 30 days, and is not the default', function () {
    expect(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.guards.tenant'))->toBe(['driver' => 'session', 'provider' => 'portal_accounts', 'remember' => 43200])
        ->and(config('auth.providers.portal_accounts'))->toBe(['driver' => 'eloquent', 'model' => PortalAccount::class])
        ->and(Auth::guard('tenant')->getRecallerName())->not->toBe(Auth::guard('web')->getRecallerName()); // its own remember cookie
    // The 30-day cookie itself is checked in SignInTest.
});

test('one account per tenant and per email; the locale is en or ar', function () {
    $account = portalAccount($this->tenant);
    $other = Customer::factory()->create(['email' => 'other@tenant.test']);

    expect($account->email)->toBe('sara@tenant.test')->and($account->locale)->toBe('en')
        ->and(fn () => portalAccount($this->tenant, ['email' => 'second@tenant.test']))->toThrow(QueryException::class)
        ->and(fn () => portalAccount($other, ['email' => 'sara@tenant.test']))->toThrow(QueryException::class)
        ->and(fn () => portalAccount($other, ['locale' => 'fr']))->toThrow(QueryException::class);
});

test('an account is active until it is disabled or its tenant is deleted, and names its tenant in the audit log', function () {
    $account = portalAccount($this->tenant);
    expect($account->isActive())->toBeTrue()->and($account->getAuthPassword())->toBe('');

    $account->forceFill(['disabled_at' => now()])->save();
    expect($account->isActive())->toBeFalse();

    $account->forceFill(['disabled_at' => null])->save();
    $this->tenant->delete();
    expect($account->fresh()->isActive())->toBeFalse()
        ->and($account->fresh()->name)->toBe('Tenant portal · Sara Tenant');
});

test('a request and a timeline row have exactly one creator: a staff user or a portal account', function () {
    $request = maintenanceRequest($this->pm, $this->unit);
    $event = $request->events()->sole();
    $account = portalAccount($this->tenant);
    $rows = fn (string $table, int $id) => DB::table($table)->where('id', $id);

    expect(fn () => $rows('maintenance_requests', $request->id)->update(['portal_account_id' => $account->id]))->toThrow(QueryException::class)
        ->and(fn () => $rows('maintenance_requests', $request->id)->update(['created_by' => null]))->toThrow(QueryException::class)
        ->and(fn () => $rows('maintenance_events', $event->id)->update(['portal_account_id' => $account->id]))->toThrow(QueryException::class)
        ->and(fn () => $rows('maintenance_events', $event->id)->update(['user_id' => null]))->toThrow(QueryException::class)
        ->and(fn () => $rows('maintenance_requests', $request->id)->update(['channel' => 'fax']))->toThrow(QueryException::class);

    $rows('maintenance_requests', $request->id)->update(['created_by' => null, 'portal_account_id' => $account->id, 'channel' => 'portal']);
    $rows('maintenance_events', $event->id)->update(['user_id' => null, 'portal_account_id' => $account->id]);

    expect($request->fresh()->channel)->toBe(MaintenanceChannel::Portal)
        ->and($request->fresh()->creatorName())->toBe('Tenant portal')
        ->and($event->fresh()->byName())->toBe('Tenant portal');

    // The staff page names the portal as the creator instead of failing on a missing user.
    $this->actingAs($this->pm)->get(route('maintenance.requests.show', $request))->assertOk()->assertSee('Tenant portal');
});

test('a document has exactly one uploader and is not shared with the tenant until staff share it', function () {
    Storage::fake('local');
    $document = app(StoreDocument::class)->handle($this->pm, $this->tenant, UploadedFile::fake()->create('rules.pdf', 10, 'application/pdf'), DocumentCategory::Other);
    $account = portalAccount($this->tenant);

    expect($document->fresh()->shared_with_tenant)->toBeFalse()
        ->and(fn () => DB::table('documents')->where('id', $document->id)->update(['uploaded_by' => null]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('documents')->where('id', $document->id)->update(['portal_account_id' => $account->id]))->toThrow(QueryException::class);
});

test('staff cannot log a request as coming from the portal, and their form does not offer it', function () {
    expect(fn () => maintenanceRequest($this->pm, $this->unit, ['channel' => 'portal']))->toThrow(ValidationException::class)
        ->and(MaintenanceChannel::staffCases())->not->toContain(MaintenanceChannel::Portal);

    Livewire::actingAs($this->pm)->test(RequestForm::class)->assertSee('WhatsApp')->assertDontSee('Tenant portal');
});

test('a tenant session never opens staff pages, and staff middleware leaves the tenant guard alone', function () {
    $account = portalAccount($this->tenant);
    $this->actingAs($account, 'tenant');

    foreach (['dashboard', 'customers.index', 'maintenance.requests.index', 'profile.edit'] as $name) {
        $this->get(route($name))->assertRedirect(route('login'))->assertSessionHasNoErrors(); // no "deactivated" message
    }
    expect(Auth::guard('tenant')->user()?->is($account))->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();
});

test('an account email is stored in lower case whoever writes it', function () {
    $other = Customer::factory()->create();

    expect(portalAccount($other, ['email' => 'Mixed@Case.TEST'])->email)->toBe('mixed@case.test')
        ->and(DB::table('portal_accounts')->where('customer_id', $other->id)->value('email'))->toBe('mixed@case.test');
});

test('a staff session stays owned by the staff user while the tenant guard is in use, so logout everywhere reaches it', function () {
    $account = portalAccount($this->tenant, ['id' => $this->pm->id + 100]); // ids differ, so the owner is unambiguous
    $this->actingAs($this->pm)->actingAs($account, 'tenant'); // both guards signed in; "tenant" is now the default guard, as on a portal route
    app()->forgetInstance('auth.driver'); // as in a real request, which first resolves it when the session is saved
    $handler = new DatabaseSessionHandler(DB::connection(), config('session.table'), 120, app()); // the production driver
    $handler->write('both-guards-session', 'payload');
    $row = fn () => DB::table(config('session.table'))->where('id', 'both-guards-session');

    expect($row()->value('user_id'))->toBe($this->pm->id);

    $this->pm->logoutEverywhere();
    expect($row()->exists())->toBeFalse();
});

test('every route in the web group takes the web guard, or for portal.* the tenant guard, unless it is a guest, public or Livewire route', function () {
    $public = ['home', 'agreements.verify', 'livewire.preview-file', 'livewire.upload-file', 'default-livewire.update', 'portal.locale'];
    $guard = fn (Route $r) => str_starts_with((string) $r->getName(), 'portal.') ? 'tenant' : 'web';
    $unguarded = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $r) => in_array('web', $r->gatherMiddleware(), true))
        ->reject(fn (Route $r) => in_array($r->getName(), $public, true) || in_array('guest:'.$guard($r), $r->gatherMiddleware(), true))
        ->reject(fn (Route $r) => in_array('auth:'.$guard($r), $r->gatherMiddleware(), true))
        ->map(fn (Route $r) => $r->uri())->values()->all();

    expect($unguarded)->toBe([]);
});
