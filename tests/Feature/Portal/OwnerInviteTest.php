<?php

use App\Actions\Portal\InviteToPortal;
use App\Actions\Portal\SetPortalAccess;
use App\Enums\RoleName as R;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Owners\Form as OwnerForm;
use App\Mail\PortalInvitation;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@rms.test']);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $this->building = Building::factory()->create();
    $this->admin = matrixUser(R::Admin, $this->building);
    $this->owner = Owner::factory()->create(['name_en' => 'Ali Owner', 'name_ar' => 'علي', 'email' => 'Ali@Owner.test']);
    // The same person as a tenant, under the same email in another case.
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'ALI@owner.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($this->building)->create()]);
    $this->page = fn () => Livewire::actingAs($this->admin, 'web')->test(OwnerForm::class, ['owner' => $this->owner->fresh()]);
});

test('staff invite an owner from the owner page: the account takes the owner email and an owner invite is queued', function () {
    ($this->page)()->assertSee('Owner portal')->assertSee('Not invited yet.')->assertSee('Invite to portal')
        ->call('invitePortal')->assertHasNoErrors()->assertSee('Re-send invite')->assertSee('Disable');

    $account = PortalAccount::query()->sole();
    expect($account->owner_id)->toBe($this->owner->id)
        ->and($account->customer_id)->toBeNull()
        ->and($account->email)->toBe('ali@owner.test')
        ->and($account->invited_by)->toBe($this->admin->id);

    $mail = Mail::queued(PortalInvitation::class)->sole();
    expect($mail->kind)->toBe('owner')->and($mail->hasTo('ali@owner.test', 'Ali Owner'))->toBeTrue();
    $mail->assertHasSubject('Your owner portal · بوابة المالك · Demo Properties W.L.L.')
        ->assertSeeInOrderInHtml(['Dear Ali Owner,', 'owner portal', route('portal.login'), 'عزيزي المالك علي', route('portal.login')], false);

    $audit = Activity::query()->where('event', 'portal.invited')->sole();
    expect($audit->causer?->is($this->admin))->toBeTrue()
        ->and($audit->subject?->is($account))->toBeTrue()
        ->and($audit->getProperty('record'))->toBe('owner')
        ->and($audit->getProperty('owner_id'))->toBe($this->owner->id)
        ->and($audit->getProperty('linked'))->toBeFalse()
        ->and($audit->getProperty('resent'))->toBeFalse();

    ($this->page)()->call('invitePortal');
    expect(Activity::query()->where('event', 'portal.invited')->latest('id')->first()->getProperty('resent'))->toBeTrue();
});

test('inviting an owner whose email has a tenant account links the owner to it: one sign-in, both sides', function () {
    $tenantAccount = portalAccount($this->tenant);

    app(InviteToPortal::class)->handle($this->admin, $this->owner);

    $account = PortalAccount::query()->sole();
    expect($account->is($tenantAccount))->toBeTrue()
        ->and($account->customer_id)->toBe($this->tenant->id)
        ->and($account->owner_id)->toBe($this->owner->id)
        ->and(Activity::query()->where('event', 'portal.invited')->sole()->getProperty('linked'))->toBeTrue();
    ($this->page)()->assertSee('Also used by tenant Sara Tenant');
    Livewire::actingAs($this->admin)->test(CustomerForm::class, ['customer' => $this->tenant])->assertSee('Also used by owner Ali Owner');

    $this->actingAs($account, 'tenant');
    $this->get(route('portal.home'))->assertOk();
    $this->get(route('portal.owner.home'))->assertOk();
});

test('inviting a tenant whose email has an owner account links the other way', function () {
    $ownerAccount = portalAccount($this->owner);

    app(InviteToPortal::class)->handle($this->admin, $this->tenant);

    expect(PortalAccount::query()->sole()->is($ownerAccount))->toBeTrue()
        ->and($ownerAccount->fresh()->customer_id)->toBe($this->tenant->id);
    Mail::assertQueued(PortalInvitation::class, fn (PortalInvitation $m) => $m->kind === 'tenant');
});

test('an email whose account already has another owner is refused', function () {
    portalAccount(Owner::factory()->create(['email' => 'ali@owner.test']));

    expect(fn () => app(InviteToPortal::class)->handle($this->admin, $this->owner))
        ->toThrow(ValidationException::class, 'Another owner\'s portal account uses this email.')
        ->and(PortalAccount::query()->where('owner_id', $this->owner->id)->exists())->toBeFalse();
});

test('linking to a disabled account is refused', function () {
    portalAccount($this->tenant, ['disabled_at' => now()]);

    expect(fn () => app(InviteToPortal::class)->handle($this->admin, $this->owner))
        ->toThrow(ValidationException::class, 'The portal account for this email is disabled. Enable it on Sara Tenant\'s page first.')
        ->and(PortalAccount::query()->sole()->owner_id)->toBeNull();
});

test('only staff who can manage owners invite, disable or enable', function () {
    $pm = matrixUser(R::PropertyManager, $this->building); // views owners, cannot manage them

    expect(fn () => app(InviteToPortal::class)->handle($pm, $this->owner))->toThrow(AuthorizationException::class);
    Livewire::actingAs($pm)->test(OwnerForm::class, ['owner' => $this->owner])->assertDontSee('Invite to portal')->call('invitePortal')->assertForbidden();

    app(InviteToPortal::class)->handle($this->admin, $this->owner);
    expect(fn () => app(SetPortalAccess::class)->handle($pm, $this->owner, false))->toThrow(AuthorizationException::class)
        ->and(PortalAccount::query()->sole()->disabled_at)->toBeNull();
});

test('no invite for an owner without an email, while mail is not set up, or once deleted', function () {
    $this->owner->update(['email' => null]);
    ($this->page)()->assertDontSee('Invite to portal')->call('invitePortal')->assertHasErrors('portal');

    $this->owner->update(['email' => 'ali@owner.test']);
    config(['mail.default' => 'log']);
    ($this->page)()->assertSee('Portal email is not set up.')->assertDontSee('Invite to portal')->call('invitePortal')->assertHasErrors('portal');
    config(['mail.default' => 'smtp']);

    $this->owner->delete();
    expect(fn () => app(InviteToPortal::class)->handle($this->admin, $this->owner))->toThrow(ValidationException::class)
        ->and(PortalAccount::count())->toBe(0);
    Mail::assertNothingQueued();
});

test('disable from the owner page ends both sides and the live session; enable from the tenant page brings both back; each audit names its record', function () {
    $account = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);
    $this->actingAs($account, 'tenant')->get(route('portal.home'))->assertOk();

    ($this->page)()->call('disablePortal')->assertHasNoErrors()->assertSee('Portal access is disabled.');
    $account->refresh(); // the test keeps one instance signed in; a real request loads the row afresh
    $this->get(route('portal.home'))->assertRedirect(route('portal.login'));

    app(SetPortalAccess::class)->handle($this->admin, $this->tenant, true);
    expect($account->fresh()->disabled_at)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['portal.disabled', 'portal.enabled'])->orderBy('id')->get()
            ->map(fn (Activity $a) => [$a->event, $a->getProperty('record')])->all())
        ->toBe([['portal.disabled', 'owner'], ['portal.enabled', 'tenant']]);
});

test('the owner page portal buttons take no arguments from the browser', function () {
    foreach (['invitePortal', 'disablePortal', 'enablePortal'] as $name) {
        $parameters = (new ReflectionMethod(OwnerForm::class, $name))->getParameters();
        expect($parameters)->toHaveCount(1)
            ->and($parameters[0]->getType()?->getName())->toBeIn([InviteToPortal::class, SetPortalAccess::class]);
    }
});

test('a losing invite race is retried on a unique violation, a deadlock or a lock wait timeout, and on nothing else', function () {
    // Not a two-connection test: the gap-lock deadlock needs two live MySQL sessions racing; the predicate is what decides the retry.
    $query = fn (int $code) => new QueryException('mysql', 'insert', [], new PDOException('x', $code));
    $deadlock = $query(40001);
    $deadlock->errorInfo = ['40001', 1213, 'Deadlock'];
    $timeout = $query(1);
    $timeout->errorInfo = ['HY000', 1205, 'Lock wait timeout'];
    $other = $query(1);
    $other->errorInfo = ['42S02', 1146, 'No table'];

    expect(InviteToPortal::retryable($deadlock))->toBeTrue()
        ->and(InviteToPortal::retryable($timeout))->toBeTrue()
        ->and(InviteToPortal::retryable(new UniqueConstraintViolationException('mysql', 'insert', [], new PDOException)))->toBeTrue()
        ->and(InviteToPortal::retryable($other))->toBeFalse()
        ->and(InviteToPortal::retryable(new RuntimeException))->toBeFalse();
});

test('"Also used by" names the other record only to staff who can view it', function () {
    portalAccount($this->tenant, ['owner_id' => $this->owner->id]);
    $leasing = matrixUser(R::Leasing, $this->building); // manages tenants here, cannot view owners

    Livewire::actingAs($leasing, 'web')->test(CustomerForm::class, ['customer' => $this->tenant])
        ->assertSee('Also used by another owner')->assertDontSee('Ali Owner');
    ($this->page)()->assertSee('Also used by tenant Sara Tenant');

    Livewire::actingAs($this->admin, 'web')->test(CustomerForm::class, ['customer' => $this->tenant])->assertSee('Also used by owner Ali Owner');
});
