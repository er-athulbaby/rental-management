<?php

use App\Actions\Buildings\EnsureDefaultFacilities;
use App\Actions\Buildings\SaveBuilding;
use App\Enums\RoleName;
use App\Livewire\Admin\Facilities;
use App\Livewire\Buildings\Form;
use App\Models\Building;
use App\Models\Facility;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
});

test('an admin adds, renames and switches off facilities; names are unique', function () {
    $page = Livewire::actingAs($this->admin)->test(Facilities::class)
        ->set('name', 'Gym')->call('add')->assertHasNoErrors()
        ->set('name', 'gym')->call('add')->assertHasErrors('name');

    $gym = Facility::sole();
    $page->call('rename', $gym->id, 'Fitness centre')->call('toggle', $gym->id);

    expect($gym->fresh()->name)->toBe('Fitness centre')->and($gym->fresh()->active)->toBeFalse();

    $this->actingAs($this->manager)->get(route('admin.facilities'))->assertForbidden();
});

test('a building ticks facilities from the list and picks parking from a dropdown', function () {
    [$gym, $pool, $old] = collect(['Gym', 'Swimming pool', 'Old sauna'])->map(fn ($n) => Facility::create(['name' => $n]))->all();
    $old->update(['active' => false]);

    Livewire::actingAs($this->manager)->test(Form::class)
        ->assertSee('Gym')->assertDontSee('Old sauna') // switched-off facilities aren't offered
        ->set('form.name', 'Marina')->set('form.type', 'residential')->set('form.parking', 'covered')
        ->set('form.facility_ids', [$gym->id, $pool->id])
        ->call('save')->assertHasNoErrors();

    $building = Building::sole();
    expect($building->parking->value)->toBe('covered')
        ->and($building->facilities()->pluck('name')->sort()->values()->all())->toBe(['Gym', 'Swimming pool']);
});

test('a building keeps a facility that was later switched off, and refuses unknown parking', function () {
    $sauna = Facility::create(['name' => 'Sauna']);
    $building = app(SaveBuilding::class)->handle($this->manager, null, ['name' => 'Marina', 'type' => 'residential', 'facility_ids' => [$sauna->id]]);
    $sauna->update(['active' => false]);

    Livewire::actingAs($this->manager)->test(Form::class, ['building' => $building])->assertSee('Sauna');

    expect(fn () => app(SaveBuilding::class)->handle($this->manager, $building, ['name' => 'Marina', 'code' => $building->code, 'type' => 'residential', 'parking' => 'rooftop']))
        ->toThrow(ValidationException::class);
});

test('a new install starts with a list of common facilities', function () {
    app(EnsureDefaultFacilities::class)();
    app(EnsureDefaultFacilities::class)(); // safe to run twice

    expect(Facility::count())->toBeGreaterThan(5)->and(Facility::where('name', 'Lift')->exists())->toBeTrue();
});
