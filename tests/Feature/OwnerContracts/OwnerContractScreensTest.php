<?php

use App\Enums\RoleName;
use App\Livewire\OwnerContracts\Form;
use App\Livewire\OwnerContracts\Index;
use App\Livewire\OwnerContracts\Show;
use App\Models\Building;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->building = Building::factory()->create(['name' => 'Seef Heights']);
    $this->units = Unit::factory()->for($this->building)->count(2)->create();
    $this->owner = Owner::factory()->create(['name_en' => 'Fatima Saleh']);
});

test('Finance creates a draft from the form, picking all units at once', function () {
    Livewire::actingAs($this->finance)->test(Form::class)
        ->set('form.owner_id', $this->owner->id)
        ->set('form.building_id', $this->building->id)
        ->call('selectAllUnits')
        ->set('form.type', 'leased')
        ->set('form.start_date', '2026-11-01')
        ->set('form.end_date', '2027-10-31')
        ->set('form.rent_amount', '15000')
        ->set('form.payment_frequency', 'yearly')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $contract = OwnerContract::sole();
    expect($contract->units()->count())->toBe(2)->and($contract->rent_amount)->toBe('15000.000');
});

test('validation errors land on the form fields', function () {
    Livewire::actingAs($this->finance)->test(Form::class)
        ->set('form.building_id', $this->building->id)
        ->set('form.type', 'managed')
        ->call('save')
        ->assertHasErrors(['form.owner_id', 'form.start_date', 'form.unit_ids', 'form.fee_value']);
});

test('a successor form is prefilled from its predecessor', function () {
    $active = activeOwnerContract(['owner_id' => $this->owner->id, 'building_id' => $this->building->id], $this->units);

    Livewire::actingAs($this->finance)->withQueryParams(['previous' => $active->id])->test(Form::class)
        ->assertSet('form.previous_contract_id', $active->id)
        ->assertSet('form.owner_id', $active->owner_id)
        ->assertSet('form.unit_ids', $this->units->pluck('id')->all());
});

test('the show page lists the contract, and only drafts offer Edit', function () {
    $draft = OwnerContract::factory()->create(['owner_id' => $this->owner->id, 'building_id' => $this->building->id]);
    $active = activeOwnerContract(['owner_id' => $this->owner->id, 'building_id' => $this->building->id], $this->units);

    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $draft])->assertSee('Fatima Saleh')->assertSee(route('owner-contracts.edit', $draft));
    Livewire::actingAs($this->finance)->test(Show::class, ['contract' => $active])->assertSee($active->number)->assertDontSee(route('owner-contracts.edit', $active));

    $this->actingAs($this->finance)->get(route('owner-contracts.edit', $active))->assertForbidden();
});

test('lists and pages follow owners.view and the building scope', function () {
    $contract = OwnerContract::factory()->create(['owner_id' => $this->owner->id, 'building_id' => $this->building->id]);
    OwnerContract::factory()->create(['building_id' => Building::factory()->create(['name' => 'Juffair Point'])]);

    Livewire::actingAs($this->finance)->test(Index::class)->assertSee('Seef Heights')->assertSee('Juffair Point');

    $pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->actingAs($pm)->get(route('owner-contracts.show', $contract))->assertOk();
    $this->actingAs($pm)->get(route('owner-contracts.create'))->assertForbidden();

    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->building->id);
    $this->actingAs($leasing)->get(route('owner-contracts.index'))->assertForbidden();
});
