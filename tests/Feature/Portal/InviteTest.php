<?php

use App\Actions\Portal\InviteToPortal;
use App\Actions\Portal\SendLoginCode;
use App\Actions\Portal\SetPortalAccess;
use App\Enums\RoleName as R;
use App\Livewire\Customers\Form;
use App\Mail\PortalInvitation;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\PortalLoginCode;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create();
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'name_ar' => 'سارة', 'email' => 'Sara@Tenant.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($this->building)->create()]);
    $this->leasing = matrixUser(R::Leasing, $this->building);
    $this->invite = fn (User $by) => app(InviteToPortal::class)->handle($by, $this->tenant->fresh());
    $this->page = fn () => Livewire::actingAs($this->leasing)->test(Form::class, ['customer' => $this->tenant->fresh()]);
});

test('staff invite a tenant from the tenant page: the account takes the tenant email and a bilingual invite is queued', function () {
    ($this->page)()->assertSee('Tenant portal')->assertSee('Not invited yet.')->assertSee('Invite to portal')
        ->call('invitePortal')->assertHasNoErrors()->assertSee('Re-send invite')->assertSee('Disable');

    $account = PortalAccount::query()->sole();
    expect($account->customer_id)->toBe($this->tenant->id)
        ->and($account->email)->toBe('sara@tenant.test')
        ->and($account->invited_by)->toBe($this->leasing->id)
        ->and($account->invited_at?->equalTo(now()))->toBeTrue()
        ->and($account->isActive())->toBeTrue()
        ->and($this->tenant->fresh()->portalAccount?->is($account))->toBeTrue();

    $mail = Mail::queued(PortalInvitation::class)->sole();
    expect($mail)->toBeInstanceOf(ShouldQueue::class)->and($mail->tries)->toBe(1)
        ->and($mail->hasTo('sara@tenant.test', 'Sara Tenant'))->toBeTrue();
    $mail->assertHasSubject('Your tenant portal · بوابة المستأجر · Demo Properties W.L.L.')
        ->assertSeeInOrderInHtml(['dir="ltr"', 'Dear Sara Tenant,', route('portal.login'), 'dir="rtl"', 'سارة', route('portal.login')], false);

    $audit = Activity::query()->where('event', 'portal.invited')->sole();
    expect($audit->causer?->is($this->leasing))->toBeTrue()
        ->and($audit->subject?->is($account))->toBeTrue()
        ->and($audit->getProperty('resent'))->toBeFalse();
});

test('re-sending keeps one account, queues another invite and is audited as a re-send', function () {
    ($this->invite)($this->leasing);
    ($this->invite)($this->leasing);

    expect(PortalAccount::count())->toBe(1);
    Mail::assertQueued(PortalInvitation::class, 2);
    expect(Activity::query()->where('event', 'portal.invited')->orderBy('id')->get()->map->getProperty('resent')->all())->toBe([false, true]);
});

test('disable and enable are audited, disabling deletes unused codes, and inviting re-enables a disabled account', function () {
    ($this->invite)($this->leasing);
    app(SendLoginCode::class)->handle('sara@tenant.test', '10.0.0.1');
    expect(PortalLoginCode::count())->toBe(1);

    $page = ($this->page)();
    $page->call('disablePortal')->assertHasNoErrors()->assertSee('Portal access is disabled.')->assertSee('Enable')->assertSee('Invite to portal');
    expect(PortalAccount::query()->sole()->disabled_at)->not->toBeNull()
        ->and(PortalLoginCode::count())->toBe(0);

    $page->call('disablePortal'); // already disabled: nothing written, nothing audited
    $page->call('enablePortal')->assertHasNoErrors()->assertSee('Re-send invite');
    expect(PortalAccount::query()->sole()->disabled_at)->toBeNull();

    $page->call('disablePortal');
    ($this->invite)($this->leasing);
    expect(PortalAccount::query()->sole()->disabled_at)->toBeNull()
        ->and(Activity::query()->where('event', 'like', 'portal.%')->orderBy('id')->pluck('event')->all())
        ->toBe(['portal.invited', 'portal.disabled', 'portal.enabled', 'portal.disabled', 'portal.invited']);
});

test('only staff who can manage the tenant can invite, disable or enable', function () {
    $finance = User::factory()->withTwoFactor()->create()->assignRole(R::Finance); // sees tenants, cannot manage them
    $elsewhere = matrixUser(R::Leasing, Building::factory()->create());

    foreach ([$finance, $elsewhere] as $user) {
        expect(fn () => ($this->invite)($user))->toThrow(AuthorizationException::class);
    }
    Livewire::actingAs($finance)->test(Form::class, ['customer' => $this->tenant])
        ->assertDontSee('Invite to portal')->call('invitePortal')->assertForbidden();

    ($this->invite)($this->leasing);
    expect(fn () => app(SetPortalAccess::class)->handle($finance, $this->tenant, false))->toThrow(AuthorizationException::class)
        ->and(PortalAccount::query()->sole()->disabled_at)->toBeNull();
});

test('no invite for a tenant without an email, or while mail is not set up', function () {
    $this->tenant->update(['email' => null]);
    ($this->page)()->assertDontSee('Invite to portal')->call('invitePortal')->assertHasErrors('portal');

    $this->tenant->update(['email' => 'sara@tenant.test']);
    config(['mail.default' => 'log']);
    ($this->page)()->assertSee('Portal email is not set up.')->assertDontSee('Invite to portal')->call('invitePortal')->assertHasErrors('portal');

    expect(PortalAccount::count())->toBe(0);
    Mail::assertNothingQueued();
});

test('an email that another tenant\'s portal account uses is refused', function () {
    portalAccount(Customer::factory()->create(['email' => 'sara@tenant.test']));

    expect(fn () => ($this->invite)($this->leasing))->toThrow(ValidationException::class)
        ->and(PortalAccount::query()->where('customer_id', $this->tenant->id)->exists())->toBeFalse();
});

test('enabling needs the tenant to have an email, and takes the current one', function () {
    ($this->invite)($this->leasing);
    app(SetPortalAccess::class)->handle($this->leasing, $this->tenant, false);
    $this->tenant->update(['email' => null]);

    expect(fn () => app(SetPortalAccess::class)->handle($this->leasing, $this->tenant->fresh(), true))->toThrow(ValidationException::class);

    $this->tenant->update(['email' => 'Sara.New@Tenant.test']);
    app(SetPortalAccess::class)->handle($this->leasing, $this->tenant->fresh(), true);
    expect(PortalAccount::query()->sole()->email)->toBe('sara.new@tenant.test');
});

test('changing the tenant email on the tenant form changes the portal account email', function () {
    ($this->invite)($this->leasing);

    ($this->page)()->set('form.email', 'New@Mail.test')->call('save')->assertHasNoErrors();

    expect(PortalAccount::query()->sole()->email)->toBe('new@mail.test');
});

test('the tenant form refuses an email another portal account uses, and removing the email while the portal is enabled', function () {
    ($this->invite)($this->leasing);
    portalAccount(Customer::factory()->create(['email' => 'taken@mail.test']));

    ($this->page)()->set('form.email', 'TAKEN@mail.test')->call('save')->assertHasErrors('form.email');
    ($this->page)()->set('form.email', '')->call('save')->assertHasErrors('form.email');
    expect(PortalAccount::query()->where('customer_id', $this->tenant->id)->sole()->email)->toBe('sara@tenant.test');

    app(SetPortalAccess::class)->handle($this->leasing, $this->tenant, false);
    ($this->page)()->set('form.email', '')->call('save')->assertHasNoErrors();
    expect($this->tenant->fresh()->email)->toBeNull()
        ->and(PortalAccount::query()->where('customer_id', $this->tenant->id)->sole()->email)->toBe('sara@tenant.test');
});

test('disabling ends an active portal session on its next request, and a deleted tenant is refused', function () {
    $account = ($this->invite)($this->leasing);
    $this->actingAs($account, 'tenant');
    $this->get(route('portal.home'))->assertOk();

    app(SetPortalAccess::class)->handle($this->leasing, $this->tenant, false);
    $account->refresh(); // the test keeps one instance signed in; a real request loads the row afresh

    $this->get(route('portal.home'))->assertRedirect(route('portal.login'));
    expect(Auth::guard('tenant')->check())->toBeFalse();

    $this->tenant->delete();
    expect(fn () => app(InviteToPortal::class)->handle($this->leasing, Customer::withTrashed()->find($this->tenant->id)))->toThrow(AuthorizationException::class);
});

test('the portal buttons take no arguments from the browser', function () {
    foreach (['invitePortal', 'disablePortal', 'enablePortal'] as $method) {
        foreach ((new ReflectionMethod(Form::class, $method))->getParameters() as $p) {
            expect($p->getType()?->isBuiltin())->toBeFalse(); // only container-injected actions, nothing from the browser
        }
    }
    ($this->page)()->call('disablePortal')->assertHasErrors('portal'); // never invited: a message, not a 500
});
