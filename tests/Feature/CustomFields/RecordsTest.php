<?php

use App\CustomFields\CustomFields;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Livewire\Buildings\Form as BuildingForm;
use App\Livewire\Owners\Form as OwnerForm;
use App\Livewire\Units\Form as UnitForm;
use App\Models\Building;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->tower = Building::factory()->create(['name' => 'Seef Tower']);
    $this->other = Building::factory()->create(['name' => 'Other Tower']);
    $this->manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->leasing = matrixUser(RoleName::Leasing, $this->tower);
    $this->meter = customField('unit', 'text', 'Meter number');
    customField('unit', 'file', 'Floor plan');
    customField('building', 'number', 'Plot area');
    $this->broker = customField('owner', 'choice', 'Broker', choices: ['Direct', 'Agency']);
});

test('units, buildings and owners save their More details with the record, in one Save', function () {
    $unit = Unit::factory()->for($this->tower)->create(['code' => '101', 'blocked' => false]);
    $owner = Owner::factory()->create();
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    Livewire::actingAs($this->manager)->test(UnitForm::class, ['unit' => $unit])
        ->set('custom.meter_number', 'EWA-778')
        ->set('custom.floor_plan', UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->manager)->test(BuildingForm::class, ['building' => $this->tower])
        ->set('custom.plot_area', '1250.75')->call('save')->assertHasNoErrors();
    Livewire::actingAs($finance)->test(OwnerForm::class, ['owner' => $owner])
        ->set('custom.broker', (string) $this->broker->choices[1]->id)->call('save')->assertHasNoErrors();

    expect(array_column(CustomFields::display($unit), 'text', 'label'))->toBe(['Meter number' => 'EWA-778', 'Floor plan' => 'plan.pdf'])
        ->and(Document::query()->where('documentable_type', $unit->getMorphClass())->where('documentable_id', $unit->id)->sole()->category)->toBe(DocumentCategory::CustomField)
        ->and(array_column(CustomFields::display($this->tower), 'text', 'label'))->toBe(['Plot area' => '1250.75'])
        ->and(array_column(CustomFields::display($owner), 'text', 'label'))->toBe(['Broker' => 'Agency']);
});

test('people who can only view see the read-only box, editors the inputs', function () {
    $unit = Unit::factory()->for($this->tower)->create(['code' => '101', 'blocked' => false]);
    customValue($this->meter, $unit, ['value_text' => 'EWA-778']);

    $this->actingAs($this->leasing)->get(route('units.edit', $unit))->assertOk()
        ->assertSeeInOrder(['More details', 'Meter number', 'EWA-778'])->assertDontSee('Floor plan');
    $this->actingAs($this->manager)->get(route('units.edit', $unit))->assertOk()->assertSee('Floor plan');
});

test('values follow the record\'s own permission and building scope', function () {
    $mine = Unit::factory()->for($this->tower)->create(['blocked' => false]);
    $theirs = Unit::factory()->for($this->other)->create(['blocked' => false]);
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);

    Livewire::actingAs($this->leasing)->test(UnitForm::class, ['unit' => $mine])
        ->set('custom.meter_number', 'X')->call('save')->assertForbidden(); // buildings.view only
    Livewire::actingAs($this->leasing)->test(UnitForm::class, ['unit' => $theirs])->assertForbidden(); // not their building
    Livewire::actingAs($management)->test(OwnerForm::class, ['owner' => Owner::factory()->create()])
        ->set('custom.broker', (string) $this->broker->choices[0]->id)->call('save')->assertHasErrors('form.iban'); // the owner form reports a refused save there

    expect(CustomFieldValue::query()->count())->toBe(0);
});

test('an error while saving a custom value rolls back the unit, building and owner change', function () {
    $unit = Unit::factory()->for($this->tower)->create(['code' => '101', 'blocked' => false, 'notes' => 'before']);
    $owner = Owner::factory()->create(['name_en' => 'Before Owner']);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    CustomFieldValue::creating(fn () => throw new RuntimeException('disk full'));

    expect(fn () => Livewire::actingAs($this->manager)->test(UnitForm::class, ['unit' => $unit])
        ->set('form.notes', 'after')->set('custom.meter_number', 'EWA-1')->call('save'))->toThrow(RuntimeException::class, 'disk full');
    expect(fn () => Livewire::actingAs($this->manager)->test(BuildingForm::class, ['building' => $this->tower])
        ->set('form.name', 'After Tower')->set('custom.plot_area', '10')->call('save'))->toThrow(RuntimeException::class, 'disk full');
    expect(fn () => Livewire::actingAs($finance)->test(OwnerForm::class, ['owner' => $owner])
        ->set('form.name_en', 'After Owner')->set('custom.broker', (string) $this->broker->choices[0]->id)->call('save'))->toThrow(RuntimeException::class, 'disk full');

    expect($unit->refresh()->notes)->toBe('before')->and($this->tower->refresh()->name)->toBe('Seef Tower')
        ->and($owner->refresh()->name_en)->toBe('Before Owner')->and(CustomFieldValue::query()->count())->toBe(0);
});

test('the read-only box starts its text on the first line, with no leading newline', function () {
    $unit = Unit::factory()->for($this->tower)->create();
    customValue($this->meter, $unit, ['value_text' => 'EWA-778']);

    $html = (string) $this->blade('<x-custom-fields.display :items="$items" />', ['items' => CustomFields::display($unit)]);

    expect($html)->toContain('whitespace-pre-line">EWA-778</dd>');
});
