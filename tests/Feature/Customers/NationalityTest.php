<?php

use App\Actions\Customers\SaveCustomer;
use App\Actions\Owners\SaveOwner;
use App\Enums\RoleName;
use App\Livewire\Customers\Form;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->customer = ['type' => 'individual', 'name_en' => 'Mohammed Ali', 'id_type' => 'cpr', 'id_number' => '900101234', 'mobile' => '+97333112233'];
    $this->owner = ['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '880101234'];
});

test('the list starts with Bahraini, then the GCC, then everyone else A–Z', function () {
    $list = config('nationalities');

    expect(array_slice($list, 0, 6))->toBe(['Bahraini', 'Saudi', 'Emirati', 'Kuwaiti', 'Omani', 'Qatari'])
        ->and($list[6])->toBe('Afghan')->and(end($list))->toBe('Zimbabwean')
        ->and($list)->toContain('Indian', 'Pakistani', 'Filipino', 'British', 'American', 'Egyptian', 'Jordanian')
        ->and(count($list))->toBe(count(array_unique($list)));

    $rest = array_slice($list, 6);
    $sorted = $rest;
    sort($sorted);
    expect($rest)->toBe($sorted);
});

test('customers and owners take a nationality from the list; others are refused; blank is fine', function () {
    expect(app(SaveCustomer::class)->handle($this->admin, null, [...$this->customer, 'nationality' => 'Indian'])->nationality)->toBe('Indian')
        ->and(app(SaveOwner::class)->handle($this->admin, null, [...$this->owner, 'nationality' => 'Filipino'])->nationality)->toBe('Filipino')
        ->and(app(SaveOwner::class)->handle($this->admin, null, [...$this->owner, 'id_number' => '1', 'nationality' => null])->nationality)->toBeNull();

    expect(fn () => app(SaveCustomer::class)->handle($this->admin, null, [...$this->customer, 'id_number' => '2', 'nationality' => 'Martian']))->toThrow(ValidationException::class);
    expect(fn () => app(SaveOwner::class)->handle($this->admin, null, [...$this->owner, 'id_number' => '3', 'nationality' => 'Martian']))->toThrow(ValidationException::class);
});

test('an existing off-list nationality survives an edit but cannot be changed to another off-list value', function () {
    $customer = app(SaveCustomer::class)->handle($this->admin, null, $this->customer);
    $customer->forceFill(['nationality' => 'Dilmunite'])->save();

    Livewire::actingAs($this->admin)->test(Form::class, ['customer' => $customer])->assertSee('Dilmunite')
        ->set('form.name_en', 'Mohammed A.')->call('save')->assertHasNoErrors();
    expect($customer->fresh()->nationality)->toBe('Dilmunite');

    expect(fn () => app(SaveCustomer::class)->handle($this->admin, $customer->fresh(), [...$this->customer, 'nationality' => 'Bahrainian']))->toThrow(ValidationException::class);

    $owner = app(SaveOwner::class)->handle($this->admin, null, $this->owner);
    $owner->forceFill(['nationality' => 'Indian national'])->save();
    expect(app(SaveOwner::class)->handle($this->admin, $owner->fresh(), [...$this->owner, 'nationality' => 'Indian national'])->nationality)->toBe('Indian national');
});
