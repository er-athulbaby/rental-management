<?php

use App\Actions\Units\SaveUnit;
use App\Enums\RoleName;
use App\Livewire\Admin\UnitTypes;
use App\Livewire\Units\Form;
use App\Livewire\Units\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
});

function saveUnit(User $actor, ?Unit $unit, array $data): Unit
{
    return app(SaveUnit::class)->handle($actor, $unit, [
        'building_id' => test()->building->id, 'code' => uniqid(), 'use' => 'commercial', 'furnishing' => 'unfurnished', 'list_rent' => '100', ...$data,
    ]);
}

test('a new install starts with the Bahrain types, Flat and Apartment being one', function () {
    expect(UnitType::where('code', 'flat')->value('name'))->toBe('Flat / Apartment')
        ->and(UnitType::where('code', 'shop')->first()->default_use->value)->toBe('commercial')
        ->and(UnitType::count())->toBe(19);
});

test('an admin adds, renames, re-uses and switches off types; names are unique and codes never change', function () {
    $page = Livewire::actingAs($this->admin)->test(UnitTypes::class)
        ->set('name', 'Car wash bay')->set('defaultUse', 'commercial')->call('add')->assertHasNoErrors()
        ->set('name', 'car wash bay')->call('add')->assertHasErrors('name');

    $bay = UnitType::where('name', 'Car wash bay')->sole();
    expect($bay->code)->toBe('car_wash_bay');

    $page->call('rename', $bay->id, 'Wash bay')->call('setUse', $bay->id, '')->call('toggle', $bay->id);
    expect($bay->fresh())->name->toBe('Wash bay')->code->toBe('car_wash_bay')->default_use->toBeNull()->active->toBeFalse();

    Livewire::actingAs($this->manager)->test(UnitTypes::class)->assertForbidden();
});

test('a switched-off type is not offered for new units, but a unit that has it keeps it', function () {
    $kiosk = saveUnit($this->manager, null, ['type' => 'kiosk']);
    UnitType::where('code', 'kiosk')->update(['active' => false]);

    expect(fn () => saveUnit($this->manager, null, ['type' => 'kiosk']))->toThrow(ValidationException::class);
    expect(saveUnit($this->manager, $kiosk, ['code' => $kiosk->code, 'type' => 'kiosk', 'list_rent' => '120'])->list_rent)->toBe('120.000');

    Livewire::actingAs($this->manager)->test(Form::class)->assertDontSee('Kiosk');
    Livewire::actingAs($this->manager)->test(Form::class, ['unit' => $kiosk])->assertSee('Kiosk');
});

test('picking a type fills in its usual use', function () {
    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.type', 'shop')->assertSet('form.use', 'commercial')
        ->set('form.type', 'villa')->assertSet('form.use', 'residential')
        ->set('form.use', 'commercial')->set('form.type', 'other')->assertSet('form.use', 'commercial');
});

test('the database rejects a type that is not on the list', function () {
    expect(fn () => DB::table('units')->insert(['building_id' => $this->building->id, 'code' => 'Z9', 'use' => 'residential', 'type' => 'castle']))
        ->toThrow(QueryException::class);
});

test('the Units list shows type names and filters by type', function () {
    saveUnit($this->manager, null, ['code' => 'S1', 'type' => 'shop']);
    saveUnit($this->manager, null, ['code' => 'F1', 'type' => 'flat', 'use' => 'residential']);

    Livewire::actingAs($this->manager)->test(Index::class)
        ->assertSee('Flat / Apartment')->assertSee('Shop')
        ->set('type', 'shop')->assertSee('S1')->assertDontSee('F1');
});

test('imports accept a type by code, by name or by either half of a name', function () {
    expect(UnitType::canonical('flat'))->toBe('flat')
        ->and(UnitType::canonical('Apartment'))->toBe('flat')
        ->and(UnitType::canonical(' land / plot '))->toBe('land')
        ->and(UnitType::canonical('castle'))->toBeNull();
});
