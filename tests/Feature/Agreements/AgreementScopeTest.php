<?php

use App\Enums\RoleName;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mine = Building::factory()->create();
    $this->theirs = Building::factory()->create();
    $this->leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->leasing->buildings()->attach($this->mine->id);

    $this->ownCustomer = Customer::factory()->create(['name_en' => 'Own Tenant']);
    $this->otherCustomer = Customer::factory()->create(['name_en' => 'Other Tenant', 'id_number' => '770707777', 'mobile' => '+97336660000']);
    $this->newCustomer = Customer::factory()->create(['name_en' => 'Walk In']);

    $this->ownAgreement = activeAgreement(['customer_id' => $this->ownCustomer->id], [Unit::factory()->for($this->mine)->create()]);
    $this->otherAgreement = activeAgreement(['customer_id' => $this->otherCustomer->id], [Unit::factory()->for($this->theirs)->create()]);
});

test('a scoped user sees agreements with a unit in an assigned building', function () {
    expect(Agreement::visibleTo($this->leasing)->pluck('id')->all())->toBe([$this->ownAgreement->id])
        ->and($this->leasing->can('view', $this->otherAgreement))->toBeFalse();

    // An agreement spanning both buildings is visible (any unit in scope) but not writable (not every unit in scope).
    $spanning = activeAgreement([], [Unit::factory()->for($this->mine)->create(), Unit::factory()->for($this->theirs)->create()]);
    expect($this->leasing->can('view', $spanning))->toBeTrue()
        ->and($this->leasing->can('update', $spanning))->toBeFalse();
});

test('customers are visible when they have an agreement in scope or none yet', function () {
    expect(Customer::visibleTo($this->leasing)->pluck('name_en')->sort()->values()->all())->toBe(['Own Tenant', 'Walk In']);

    $admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    expect(Customer::visibleTo($admin)->count())->toBe(3);
});

test('an exact ID or mobile match outside the scope shows only that the customer exists', function (string $term) {
    Livewire::actingAs($this->leasing)->test(CustomersIndex::class)
        ->set('search', $term)
        ->assertDontSee('770707777')
        ->assertSee('Already exists: Other Tenant, ID ••••7777');
})->with(['770707777', '+97336660000']);
