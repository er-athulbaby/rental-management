<?php

use App\Actions\Buildings\SaveBuilding;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Livewire\Buildings\Form;
use App\Livewire\Buildings\Index;
use App\Livewire\Documents\Panel;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
});

test('the list respects building assignment', function () {
    [$a, $b] = Building::factory()->count(2)->create()->all();
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($a->id);

    Livewire::actingAs($leasing)->test(Index::class)->assertSee($a->name)->assertDontSee($b->name);
    Livewire::actingAs($this->manager)->test(Index::class)->assertSee($a->name)->assertSee($b->name);
});

test('users without buildings.view cannot open the list', function () {
    $this->actingAs(User::factory()->create())->get(route('buildings.index'))->assertForbidden();
    $this->actingAs($this->manager)->get(route('buildings.index'))->assertOk();
});

test('a property manager creates and edits a building', function () {
    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.name', 'Marina Tower')
        ->set('form.code', 'MT-01')
        ->set('form.type', 'residential')
        ->set('form.floors_count', 12)
        ->call('save')
        ->assertHasNoErrors();

    $building = Building::where('code', 'MT-01')->firstOrFail();

    Livewire::actingAs($this->manager)->test(Form::class, ['building' => $building])
        ->set('form.name', 'Marina Tower A')
        ->call('save')
        ->assertHasNoErrors();

    expect($building->fresh()->name)->toBe('Marina Tower A');
});

test('codes are unique and required fields are validated', function () {
    Building::factory()->create(['code' => 'DUP']);

    Livewire::actingAs($this->manager)->test(Form::class)
        ->set('form.name', '')
        ->set('form.code', 'DUP')
        ->set('form.type', 'palace')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.code', 'form.type']);
});

test('Leasing cannot edit buildings', function () {
    $building = Building::factory()->create();
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($building->id);

    Livewire::actingAs($leasing)->test(Form::class, ['building' => $building])
        ->set('form.name', 'Hacked')
        ->call('save')
        ->assertForbidden();
});

test('the documents panel uploads, lists and deletes', function () {
    Storage::fake('local');
    $building = Building::factory()->create();

    $panel = Livewire::actingAs($this->manager)->test(Panel::class, ['documentable' => $building])
        ->set('upload', UploadedFile::fake()->create('title-deed.pdf', 20, 'application/pdf'))
        ->set('category', DocumentCategory::Other->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('title-deed.pdf');

    $document = $building->documents()->firstOrFail();
    $panel->call('delete', $document->id)->assertDontSee('title-deed.pdf');

    expect($building->documents()->count())->toBe(0);
});

test('the documents panel refuses models outside its allow-list', function () {
    Livewire::actingAs($this->manager)->test(Panel::class, ['documentable' => $this->manager])->assertForbidden();
});

test('a rejected upload shows an error on the upload field and stores nothing', function () {
    Storage::fake('local');
    $building = Building::factory()->create();

    Livewire::actingAs($this->manager)->test(Panel::class, ['documentable' => $building])
        ->set('upload', UploadedFile::fake()->create('malware.exe', 5))
        ->call('save')
        ->assertHasErrors('upload');

    expect($building->documents()->count())->toBe(0);
});

test('a building left without a code gets the next B number', function () {
    Building::factory()->create(['code' => 'B007']);
    Building::factory()->create(['code' => 'MT']); // a hand-typed code doesn't count

    $save = fn () => app(SaveBuilding::class)->handle($this->manager, null, ['name' => 'New tower', 'code' => '', 'type' => 'residential']);

    expect($save()->code)->toBe('B008')->and($save()->code)->toBe('B009');
});

test('a typed building code is kept, and an existing building must keep a code', function () {
    $building = app(SaveBuilding::class)->handle($this->manager, null, ['name' => 'Marina', 'code' => 'MT', 'type' => 'residential']);
    expect($building->code)->toBe('MT');

    expect(fn () => app(SaveBuilding::class)->handle($this->manager, $building, ['name' => 'Marina', 'code' => '', 'type' => 'residential']))
        ->toThrow(ValidationException::class);
});
