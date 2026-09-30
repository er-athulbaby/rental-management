<?php

use App\Actions\Customers\SaveCustomer;
use App\Enums\RoleName;
use App\Livewire\Customers\Form;
use App\Livewire\Documents\Panel;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->person = [
        'type' => 'individual', 'name_en' => 'Mohammed Ali', 'name_ar' => 'محمد علي', 'id_type' => 'cpr', 'id_number' => '900101234',
        'nationality' => 'Bahraini', 'mobile' => '+97333112233', 'email' => 'm.ali@example.test',
    ];
});

test('Leasing records an individual customer', function () {
    $customer = app(SaveCustomer::class)->handle($this->leasing, null, $this->person);

    expect($customer->name_en)->toBe('Mohammed Ali')
        ->and($customer->maskedId())->toBe('••••1234')
        ->and($customer->displayName('ar'))->toBe('محمد علي');
});

test('companies use a CR and people a CPR or passport', function () {
    $company = app(SaveCustomer::class)->handle($this->leasing, null, [
        'type' => 'company', 'name_en' => 'Gulf Trading W.L.L.', 'id_type' => 'cr', 'id_number' => '12345-1', 'contact_person' => 'Sara', 'mobile' => '+97317000000',
    ]);
    expect($company->displayName('ar'))->toBe('Gulf Trading W.L.L.'); // falls back to English

    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'id_type' => 'cr', 'id_number' => '5']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, ['type' => 'company', 'name_en' => 'X', 'id_type' => 'cpr', 'id_number' => '6', 'mobile' => '+9731']))
        ->toThrow(ValidationException::class);
});

test('a duplicate ID names the existing customer with a masked ID only', function () {
    app(SaveCustomer::class)->handle($this->leasing, null, $this->person);

    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'name_en' => 'Someone Else']))
        ->toThrow(ValidationException::class, 'Already exists: Mohammed Ali, ID ••••1234');
});

test('Management views customers but cannot create them', function () {
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    $customer = Customer::factory()->create();

    expect($management->can('view', $customer))->toBeTrue()
        ->and(fn () => app(SaveCustomer::class)->handle($management, null, $this->person))->toThrow(AuthorizationException::class);

    $this->actingAs($management)->get(route('customers.create'))->assertForbidden();
    $this->actingAs($this->leasing)->get(route('customers.create'))->assertOk();
});

test('the form saves and an ID copy keeps its expiry date', function () {
    Storage::fake('local');

    Livewire::actingAs($this->leasing)->test(Form::class)
        ->set('form.type', 'individual')
        ->set('form.name_en', 'Aisha Noor')
        ->set('form.id_type', 'passport')
        ->set('form.id_number', 'P1234567')
        ->set('form.mobile', '+97339998877')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $customer = Customer::where('id_number', 'P1234567')->sole();

    Livewire::actingAs($this->leasing)->test(Panel::class, ['documentable' => $customer])
        ->set('upload', UploadedFile::fake()->create('passport.pdf', 20, 'application/pdf'))
        ->set('category', 'id_copy')
        ->set('expiresOn', '2028-05-31')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('31/05/2028');

    expect($customer->documents()->sole()->expires_on->toDateString())->toBe('2028-05-31');
});
