<?php

use App\Actions\Units\SaveUnit;
use App\Enums\RoleName;
use App\Enums\TaxCategory;
use App\Enums\UnitStatus;
use App\Livewire\Units\Form;
use App\Livewire\Units\Index;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->building = Building::factory()->create();
    $this->manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
});

test('a property manager creates a unit with 3-decimal money', function () {
    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.building_id', $this->building->id)
        ->set('form.code', '502')
        ->set('form.floor', '5')
        ->set('form.use', 'residential')
        ->set('form.type', 'flat')
        ->set('form.furnishing', 'unfurnished')
        ->set('form.bedrooms', 2)
        ->set('form.list_rent', '450.500')
        ->set('form.list_deposit', '450')
        ->set('form.list_service_charge', '12.250')
        ->call('save')
        ->assertHasNoErrors();

    $unit = Unit::where('code', '502')->firstOrFail();
    expect($unit->list_rent)->toBe('450.500')->and($unit->list_deposit)->toBe('450.000');
});

test('money with more than 3 decimals and duplicate codes are rejected', function () {
    Unit::factory()->for($this->building)->create(['code' => '101']);

    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.building_id', $this->building->id)
        ->set('form.code', '101')
        ->set('form.use', 'residential')
        ->set('form.type', 'flat')
        ->set('form.furnishing', 'unfurnished')
        ->set('form.list_rent', '1.2345')
        ->call('save')
        ->assertHasErrors(['form.code', 'form.list_rent']);
});

test('the database rejects bad enum values', function () {
    // Raw insert: the enum cast would throw in PHP before MySQL saw the value.
    expect(fn () => DB::table('units')->insert(['building_id' => $this->building->id, 'code' => 'Z9', 'use' => 'castle', 'type' => 'flat']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});

test('status is Blocked or Available until agreements exist', function () {
    $unit = Unit::factory()->for($this->building)->create();
    expect($unit->status())->toBe(UnitStatus::Available);

    $unit->update(['blocked' => true, 'blocked_reason' => 'Renovation']);
    expect($unit->fresh()->status())->toBe(UnitStatus::Blocked);
});

test('the tax category falls back to the settings default for its use', function () {
    $flat = Unit::factory()->for($this->building)->create(['use' => 'residential', 'default_tax_category' => null]);
    $shop = Unit::factory()->for($this->building)->create(['use' => 'commercial', 'default_tax_category' => null]);
    $override = Unit::factory()->for($this->building)->create(['use' => 'commercial', 'default_tax_category' => 'zero_rated']);

    expect($flat->effectiveTaxCategory())->toBe(TaxCategory::Exempt)
        ->and($shop->effectiveTaxCategory())->toBe(TaxCategory::Standard)
        ->and($override->effectiveTaxCategory())->toBe(TaxCategory::ZeroRated);
});

test('units follow the building scope, for lists and for writes', function () {
    $other = Building::factory()->create();
    $mine = Unit::factory()->for($this->building)->create(['code' => 'MINE-1']);
    Unit::factory()->for($other)->create(['code' => 'THEIRS-1']);
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->building->id);

    Livewire::actingAs($leasing)->test(Index::class)->assertSee('MINE-1')->assertDontSee('THEIRS-1');
    expect($leasing->can('view', $mine))->toBeTrue()->and($leasing->can('update', $mine))->toBeFalse();

    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.building_id', $other->id)
        ->set('form.code', 'X1')
        ->set('form.use', 'residential')
        ->set('form.type', 'flat')
        ->set('form.furnishing', 'unfurnished')
        ->set('form.list_rent', '100')
        ->call('save')
        ->assertHasNoErrors(); // Property Mgr has buildings.view-all
});

test('unit writes are denied outside the actor building scope', function () {
    $other = Building::factory()->create();
    $theirs = Unit::factory()->for($other)->create(['code' => 'THEIRS-2']);
    $scoped = User::factory()->create()->assignRole(RoleName::Leasing);
    $scoped->givePermissionTo('buildings.manage');
    $scoped->buildings()->attach($this->building->id);

    $data = ['use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '100'];
    $save = app(SaveUnit::class);

    expect(fn () => $save->handle($scoped, null, [...$data, 'building_id' => $other->id, 'code' => 'N1']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $save->handle($scoped, $theirs, [...$data, 'building_id' => $other->id, 'code' => 'THEIRS-2', 'list_rent' => '999']))
        ->toThrow(AuthorizationException::class);

    Livewire::actingAs($scoped)->test(Form::class)
        ->set('form.building_id', $other->id)
        ->set('form.code', 'N2')
        ->set('form.use', 'residential')
        ->set('form.type', 'flat')
        ->set('form.furnishing', 'unfurnished')
        ->set('form.list_rent', '100')
        ->call('save')
        ->assertForbidden();

    expect(Unit::where('building_id', $other->id)->count())->toBe(1)
        ->and($theirs->fresh()->list_rent)->toBe('400.000')
        ->and($save->handle($scoped, null, [...$data, 'building_id' => $this->building->id, 'code' => 'OWN-1'])->exists)->toBeTrue();
});

test('a unit left without a code gets its floor plus the next number on that floor', function () {
    Unit::factory()->for($this->building)->create(['code' => '101', 'floor' => '1']);
    $save = fn (?string $floor) => app(SaveUnit::class)->handle($this->manager, null, [
        'building_id' => $this->building->id, 'code' => '', 'floor' => $floor, 'use' => 'residential', 'type' => 'flat', 'furnishing' => 'unfurnished', 'list_rent' => '400',
    ]);

    expect($save('1')->code)->toBe('102')
        ->and($save('1')->code)->toBe('103')
        ->and($save('2')->code)->toBe('201')
        ->and($save('G')->code)->toBe('G01')
        ->and($save('12')->code)->toBe('1201');

    expect(fn () => $save(null))->toThrow(ValidationException::class, 'floor');
});
