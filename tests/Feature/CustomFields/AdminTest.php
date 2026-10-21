<?php

use App\Actions\CustomFields\DeleteCustomField;
use App\Actions\CustomFields\MoveCustomField;
use App\Actions\CustomFields\SaveCustomField;
use App\Actions\CustomFields\SetCustomFieldArchived;
use App\Enums\RoleName;
use App\Livewire\Admin\CustomFieldSettings;
use App\Models\CustomField;
use App\Models\CustomFieldChoice;
use App\Models\CustomFieldValue;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->leasing = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Leasing);
    $this->save = fn (array $data, ?CustomField $field = null) => app(SaveCustomField::class)->handle($this->admin, $field, [
        'entity' => 'unit', 'label_en' => 'Meter number', 'label_ar' => '', 'type' => 'text', 'help' => '', 'required' => false, 'in_portal' => false,
        'choices' => [], ...$data,
    ]);
    $this->choice = fn (string $en, ?int $id = null, bool $hidden = false, string $ar = '') => ['id' => $id, 'label_en' => $en, 'label_ar' => $ar, 'hidden' => $hidden];
});

test('Admin adds a field on the Units tab; the key comes from the English label and the field goes last', function () {
    customField('unit', 'text', 'Plot');

    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)
        ->set('entity', 'unit')
        ->call('create')
        ->set('form.label_en', 'Meter number')
        ->set('form.label_ar', 'رقم العداد')
        ->set('form.type', 'number')
        ->set('form.required', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('meter_number');

    $field = CustomField::query()->where('key', 'meter_number')->sole();
    expect($field->entity->value)->toBe('unit')
        ->and($field->label('ar'))->toBe('رقم العداد')
        ->and($field->type->value)->toBe('number')
        ->and($field->required)->toBeTrue()
        ->and($field->in_portal)->toBeFalse()
        ->and($field->position)->toBe(2)
        ->and($field->created_by)->toBe($this->admin->id);
});

test('only settings.manage reaches the page and the actions', function () {
    $field = customField('unit', 'text', 'Plot');

    $this->actingAs($this->leasing)->get(route('admin.custom-fields'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.custom-fields'))->assertOk()->assertSee('Custom fields');
    Livewire::actingAs($this->leasing)->test(CustomFieldSettings::class)->assertForbidden();
    expect(fn () => app(SaveCustomField::class)->handle($this->leasing, null, ['entity' => 'unit', 'label_en' => 'X', 'type' => 'text']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetCustomFieldArchived::class)->handle($this->leasing, $field, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteCustomField::class)->handle($this->leasing, $field))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MoveCustomField::class)->handle($this->leasing, $field, 1))->toThrow(AuthorizationException::class);
});

test('once a value exists the type is locked; label, help, required, portal and choices still change, and a used choice is hidden, not deleted', function () {
    $field = ($this->save)(['type' => 'choice', 'label_en' => 'Parking', 'choices' => [($this->choice)('Covered'), ($this->choice)('Open'), ($this->choice)('None')]]);
    [$covered, $open, $none] = $field->choices()->get()->all();
    customValue($field, Unit::factory()->create(), ['choice_id' => $covered->id]);

    expect(fn () => ($this->save)(['type' => 'text', 'label_en' => 'Parking'], $field))->toThrow(ValidationException::class, 'The type cannot change once values are saved.');

    // Rename Open, leave out Covered (used) and None (unused), add Basement.
    ($this->save)(['type' => 'choice', 'label_en' => 'Parking bay', 'help' => 'As on the title deed', 'required' => true, 'in_portal' => true,
        'choices' => [($this->choice)('Open air', $open->id, ar: 'مكشوف'), ($this->choice)('Basement')]], $field);

    $field->refresh();
    $choices = $field->choices()->get();
    expect($field->label_en)->toBe('Parking bay')->and($field->key)->toBe('parking')->and($field->help)->toBe('As on the title deed')
        ->and($field->required)->toBeTrue()->and($field->in_portal)->toBeTrue()
        ->and($choices->firstWhere('id', $covered->id)?->hidden_at)->not->toBeNull()
        ->and(CustomFieldChoice::query()->find($none->id))->toBeNull()
        ->and($choices->whereNull('hidden_at')->pluck('label_en')->values()->all())->toBe(['Open air', 'Basement'])
        ->and($choices->firstWhere('id', $open->id)?->label('ar'))->toBe('مكشوف');
});

test('before any value is saved the type can change; a choice list needs a choice and distinct labels', function () {
    $field = ($this->save)([]);
    ($this->save)(['type' => 'date'], $field);
    expect($field->refresh()->type->value)->toBe('date');

    expect(fn () => ($this->save)(['type' => 'choice'], $field))->toThrow(ValidationException::class, 'A choice list needs at least one choice.')
        ->and(fn () => ($this->save)(['type' => 'choice', 'choices' => [($this->choice)('Sea'), ($this->choice)('sea')]], $field))->toThrow(ValidationException::class);

    ($this->save)(['type' => 'choice', 'choices' => [($this->choice)('Sea')]], $field);
    ($this->save)(['type' => 'text'], $field); // leaving "choice list" with no values deletes the choices
    expect(CustomFieldChoice::query()->count())->toBe(0);
});

test('archive keeps values and can be restored; delete only before any value; at most 30 active fields per kind', function () {
    $used = ($this->save)(['label_en' => 'Used']);
    $unused = ($this->save)(['label_en' => 'Unused']);
    customValue($used, Unit::factory()->create(), ['value_text' => 'x']);

    expect(fn () => app(DeleteCustomField::class)->handle($this->admin, $used))->toThrow(ValidationException::class, 'This field has saved values: archive it instead.');
    app(SetCustomFieldArchived::class)->handle($this->admin, $used, true);
    app(DeleteCustomField::class)->handle($this->admin, $unused);
    expect($used->refresh()->archived_at)->not->toBeNull()
        ->and(CustomFieldValue::query()->count())->toBe(1)
        ->and(CustomField::query()->find($unused->id))->toBeNull();

    foreach (range(1, 30) as $n) {
        customField('unit', 'text', "Field {$n}");
    }
    $full = 'At most 30 active fields per record kind. Archive one first.';
    expect(fn () => ($this->save)(['label_en' => 'One too many']))->toThrow(ValidationException::class, $full)
        ->and(fn () => app(SetCustomFieldArchived::class)->handle($this->admin, $used, false))->toThrow(ValidationException::class, $full);

    ($this->save)(['entity' => 'building', 'label_en' => 'Other kind']); // the cap is per kind
    CustomField::query()->where('label_en', 'Field 1')->sole()->forceFill(['archived_at' => now()])->save();
    app(SetCustomFieldArchived::class)->handle($this->admin, $used, false);
    expect($used->refresh()->archived_at)->toBeNull();
});

test('moving swaps a field with its active neighbour; the first field does not move up', function () {
    $a = ($this->save)(['label_en' => 'Alpha']);
    $b = ($this->save)(['label_en' => 'Beta']);
    $c = ($this->save)(['label_en' => 'Gamma']);

    app(MoveCustomField::class)->handle($this->admin, $c, -1);
    app(MoveCustomField::class)->handle($this->admin, $a, -1);
    expect(CustomField::query()->where('entity', 'unit')->orderBy('position')->pluck('label_en')->all())->toBe(['Alpha', 'Gamma', 'Beta']);

    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->set('entity', 'unit')->call('move', $b->id, -1);
    expect(CustomField::query()->where('entity', 'unit')->orderBy('position')->pluck('label_en')->all())->toBe(['Alpha', 'Beta', 'Gamma']);
});

test('adding, changing, moving, archiving, restoring and deleting a field are audited with the admin as causer', function () {
    $a = ($this->save)(['label_en' => 'Alpha']);
    $b = ($this->save)(['label_en' => 'Beta']);
    ($this->save)(['label_en' => 'Alpha', 'required' => true], $a);
    app(MoveCustomField::class)->handle($this->admin, $b, -1);
    app(SetCustomFieldArchived::class)->handle($this->admin, $a, true);
    app(SetCustomFieldArchived::class)->handle($this->admin, $a, false);
    app(DeleteCustomField::class)->handle($this->admin, $b);

    $events = Activity::query()->where('event', 'like', 'custom_field.%')->orderBy('id')->get();
    expect($events->pluck('event')->all())->toBe(['custom_field.created', 'custom_field.created', 'custom_field.updated', 'custom_field.moved',
        'custom_field.archived', 'custom_field.restored', 'custom_field.deleted'])
        ->and($events->every(fn (Activity $e) => $e->causer_id === $this->admin->id))->toBeTrue()
        ->and($events[2]->attribute_changes['old']['required'])->toBeFalse()
        ->and($events[2]->attribute_changes['attributes']['required'])->toBeTrue()
        ->and(array_keys($events[2]->attribute_changes['attributes']))->toBe(['required']);
});

test('tampered ids and kinds give a 404 or a message, never a 500', function () {
    $parking = customField('unit', 'choice', 'Parking', choices: ['Covered']);
    $sea = customField('unit', 'choice', 'View', choices: ['Sea'])->choices->sole();

    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->call('edit', 'abc')->assertNotFound();
    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->call('edit', $parking->id)->assertNotFound(); // the Tenants tab: another kind
    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->set('entity', 'unit')->call('move', $parking->id, 5)->assertNotFound();
    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->set('entity', 'invoice')->assertNotFound();
    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->set('entity', 'unit')->call('edit', $parking->id)
        ->set('choices.0.id', $sea->id)->call('save')->assertHasErrors('choices.0.id');
});
