<?php

use App\Actions\Customers\SaveCustomer;
use App\Actions\Owners\SaveOwner;
use App\Enums\RoleName as R;
use App\Livewire\Owners\Form as OwnerForm;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $building = Building::factory()->create();
    $this->admin = matrixUser(R::Admin, $building);
    $this->leasing = matrixUser(R::Leasing, $building);
    $this->owner = Owner::factory()->create(['name_en' => 'Ali Owner', 'email' => 'ali@owner.test']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'ali@owner.test']);
    activeAgreement(['customer_id' => $this->tenant->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($building)->create()]);
    $this->ownerData = fn (array $changes) => [
        ...$this->owner->fresh()->only(['name_en', 'name_ar', 'id_number', 'nationality', 'phone', 'email', 'address', 'notes', ...Owner::BANK_FIELDS]),
        'type' => 'person', 'id_type' => 'cpr', ...$changes,
    ];
    $this->tenantData = fn (array $changes) => [
        ...$this->tenant->fresh()->only(['name_en', 'name_ar', 'id_number', 'nationality', 'mobile', 'email', 'address', 'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes']),
        'type' => 'individual', 'id_type' => 'cpr', ...$changes,
    ];
});

test('an owner-only account follows the owner email from the owner page; a new address ends remembered devices', function () {
    $account = portalAccount($this->owner, ['remember_token' => 'old-token']);

    Livewire::actingAs($this->admin)->test(OwnerForm::class, ['owner' => $this->owner])
        ->set('form.email', 'Ali.New@Owner.test')->call('save')->assertHasNoErrors();

    expect($account->fresh()->email)->toBe('ali.new@owner.test')->and($account->fresh()->remember_token)->toBeNull();
});

test('an owner-only account: the email cannot be cleared while enabled, nor take another account\'s email', function () {
    portalAccount($this->owner);
    portalAccount(Owner::factory()->create(['email' => 'taken@owner.test']));

    expect(fn () => app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['email' => null])))
        ->toThrow(ValidationException::class, 'This owner uses the portal. Disable their portal access before removing the email.')
        ->and(fn () => app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['email' => 'TAKEN@owner.test'])))
        ->toThrow(ValidationException::class, 'Another owner\'s portal account uses this email.');
    Livewire::actingAs($this->admin)->test(OwnerForm::class, ['owner' => $this->owner])->set('form.email', '')->call('save')->assertHasErrors('form.email');
    expect(PortalAccount::query()->where('owner_id', $this->owner->id)->sole()->email)->toBe('ali@owner.test');
});

test('a shared account refuses an email on one record that the other record does not share', function () {
    portalAccount($this->tenant, ['owner_id' => $this->owner->id]);

    expect(fn () => app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['email' => 'new@owner.test'])))
        ->toThrow(ValidationException::class, 'This email is also the portal sign-in for Sara Tenant; change both together.')
        ->and(fn () => app(SaveCustomer::class)->handle($this->leasing, $this->tenant, ($this->tenantData)(['email' => 'new@owner.test'])))
        ->toThrow(ValidationException::class, 'This email is also the portal sign-in for Ali Owner; change both together.')
        ->and(PortalAccount::query()->sole()->email)->toBe('ali@owner.test');
});

test('to move a shared account to a new email: clear it on one record, change the other, then set the first again', function () {
    $account = portalAccount($this->tenant, ['owner_id' => $this->owner->id]);

    app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['email' => null]));
    expect($account->fresh()->email)->toBe('ali@owner.test'); // the tenant still signs in with it

    app(SaveCustomer::class)->handle($this->leasing, $this->tenant->fresh(), ($this->tenantData)(['email' => 'Both.New@Owner.test']));
    expect($account->fresh()->email)->toBe('both.new@owner.test');

    app(SaveOwner::class)->handle($this->admin, $this->owner->fresh(), ($this->ownerData)(['email' => 'both.new@owner.test']));
    expect($account->fresh()->email)->toBe('both.new@owner.test')->and($account->fresh()->disabled_at)->toBeNull();
});
