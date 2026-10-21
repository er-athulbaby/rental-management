<?php

use App\Actions\Customers\SaveCustomer;
use App\Actions\EnsureNumberSequences;
use App\Actions\Owners\SaveOwner;
use App\Enums\RoleName as R;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Owners\Form as OwnerForm;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Owner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->building = Building::factory()->create();
    $this->admin = matrixUser(R::Admin, $this->building);
    $this->leasing = matrixUser(R::Leasing, $this->building);
    $this->owner = Owner::factory()->create(['name_en' => 'Ali Owner', 'email' => 'ali@owner.test']);
    $this->tenant = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'ali@owner.test']);
    $this->ownerData = fn (array $c) => [
        ...$this->owner->fresh()->only(['name_en', 'name_ar', 'id_number', 'nationality', 'phone', 'email', 'address', 'notes', ...Owner::BANK_FIELDS]),
        'type' => 'person', 'id_type' => 'cpr', ...$c,
    ];
    $this->tenantData = fn (array $c) => [
        ...$this->tenant->fresh()->only(['name_en', 'name_ar', 'id_number', 'nationality', 'mobile', 'email', 'address', 'contact_person', 'emergency_contact_name', 'emergency_contact_phone', 'notes']),
        'type' => 'individual', 'id_type' => 'cpr', ...$c,
    ];
    $this->violation = fn (string $index) => new UniqueConstraintViolationException('mysql', 'insert', [], new PDOException("Duplicate entry 'x' for key '$index'"));
});

test('the payments bank line drops the bank name when the owner has none', function () {
    $a = portalOwnerFixture('Ali Owner', '101', $this->admin);
    $a['owner']->forceFill(['bank_name' => null])->save();

    $this->actingAs($a['account'], 'tenant')->get(route('portal.owner.payments'))->assertOk()
        ->assertSee('Bank transfers are paid to IBAN ••••0101.')->assertDontSee('paid to , IBAN');
});

test('only a portal-email violation shows the email message; another unique index is rethrown', function () {
    $other = ($this->violation)('owners_id_type_id_number_unique');
    Owner::saving(fn () => throw $other);
    Customer::saving(fn () => throw $other);

    expect(fn () => app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['phone' => '123'])))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => app(SaveCustomer::class)->handle($this->admin, $this->tenant, ($this->tenantData)(['mobile' => '123'])))->toThrow(UniqueConstraintViolationException::class);
});

test('a portal-email unique violation still shows the email message', function () {
    $email = ($this->violation)('portal_accounts_email_unique');
    Owner::saving(fn () => throw $email);
    Customer::saving(fn () => throw $email);

    expect(fn () => app(SaveOwner::class)->handle($this->admin, $this->owner, ($this->ownerData)(['phone' => '123'])))
        ->toThrow(ValidationException::class, 'Another owner\'s portal account uses this email.')
        ->and(fn () => app(SaveCustomer::class)->handle($this->admin, $this->tenant, ($this->tenantData)(['mobile' => '123'])))
        ->toThrow(ValidationException::class, 'Another tenant\'s portal account uses this email.');
});

test('the shared-email message names the other record only to staff who can view it', function () {
    portalAccount($this->tenant, ['owner_id' => $this->owner->id]);

    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, $this->tenant, ($this->tenantData)(['email' => 'new@owner.test'])))
        ->toThrow(ValidationException::class, 'This email is also the portal sign-in for another owner; change both together.')
        ->and(fn () => app(SaveCustomer::class)->handle($this->admin, $this->tenant, ($this->tenantData)(['email' => 'new@owner.test'])))
        ->toThrow(ValidationException::class, 'This email is also the portal sign-in for Ali Owner; change both together.');
});

test('the disable confirmation warns on a shared account and stays plain on an unshared one', function () {
    $account = portalAccount($this->owner);
    Livewire::actingAs($this->admin, 'web')->test(OwnerForm::class, ['owner' => $this->owner])
        ->assertSee('Disable this owner')->assertDontSee('This also ends their access as');
    Livewire::actingAs($this->admin, 'web')->test(CustomerForm::class, ['customer' => Customer::factory()->create(['email' => 'solo@t.test'])])
        ->assertDontSee('This also ends their access as');

    $account->forceFill(['customer_id' => $this->tenant->id])->save();
    Livewire::actingAs($this->admin, 'web')->test(OwnerForm::class, ['owner' => $this->owner])->assertSee('This also ends their access as tenant.');
    Livewire::actingAs($this->admin, 'web')->test(CustomerForm::class, ['customer' => $this->tenant])->assertSee('This also ends their access as owner.');
});
