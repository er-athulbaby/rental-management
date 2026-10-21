<?php

use App\Actions\Customers\SaveCustomer;
use App\CustomFields\CustomFields;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Livewire\Customers\Form;
use App\Livewire\Documents\Panel;
use App\Models\Building;
use App\Models\Customer;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->leasing = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Leasing);
    $this->person = [
        'type' => 'individual', 'name_en' => 'Mohammed Ali', 'id_type' => 'cpr', 'id_number' => '900101234', 'mobile' => '+97333112233',
    ];
    customField('customer', 'text', 'Employer', ['required' => true]);
    customField('customer', 'long_text', 'Notes for staff');
    customField('customer', 'number', 'Monthly income');
    customField('customer', 'date', 'Visa expiry');
    customField('customer', 'bool', 'Has pets');
    $source = customField('customer', 'choice', 'Source', choices: ['Walk-in', 'Agent', ['Website', 'الموقع']]);
    [$this->walkIn, $this->agent, $this->website] = $source->choices->all();
    $this->letter = customField('customer', 'file', 'Work letter');
});

test('the tenant form saves every type with the same Save, and the values read back exactly', function () {
    Livewire::actingAs($this->leasing)->test(Form::class)
        ->set('form.name_en', 'Aisha Noor')->set('form.id_type', 'passport')->set('form.id_number', 'P1234567')->set('form.mobile', '+97339998877')
        ->set('custom.employer', '  Bapco  ')
        ->set('custom.notes_for_staff', "Line one\n<b>Line two</b>")
        ->set('custom.monthly_income', '123456789012.5')
        ->set('custom.visa_expiry', '2027-03-31')
        ->set('custom.has_pets', '0')
        ->set('custom.source', (string) $this->website->id)
        ->set('custom.work_letter', UploadedFile::fake()->create('letter.pdf', 20, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $customer = Customer::query()->where('id_number', 'P1234567')->sole();
    $income = CustomFieldValue::query()->where('entity_id', $customer->id)->whereNotNull('value_number')->sole();
    expect($income->value_number)->toBe('123456789012.500')
        ->and(array_column(CustomFields::display($customer), 'text', 'label'))->toBe([
            'Employer' => 'Bapco', 'Notes for staff' => "Line one\n<b>Line two</b>", 'Monthly income' => '123456789012.5', 'Visa expiry' => '31/03/2027',
            'Has pets' => 'No', 'Source' => 'Website', 'Work letter' => 'letter.pdf',
        ])
        ->and(Document::query()->where('documentable_id', $customer->id)->sole()->category)->toBe(DocumentCategory::CustomField);

    $audit = Activity::query()->where('event', 'custom_fields.changed')->sole();
    expect($audit->causer_id)->toBe($this->leasing->id)->and($audit->subject_id)->toBe($customer->id)
        ->and($audit->attribute_changes['attributes']['Monthly income'])->toBe('123456789012.5')
        ->and($audit->attribute_changes['old']['Monthly income'])->toBeNull();
});

test('long text is escaped; people who cannot edit see a read-only box, editors the inputs', function () {
    $customer = app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => ['employer' => 'Bapco', 'notes_for_staff' => '<b>Bold</b>']]);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    $this->actingAs($finance)->get(route('customers.edit', $customer))->assertOk()
        ->assertSeeInOrder(['More details', 'Employer', 'Bapco', 'Notes for staff', '&lt;b&gt;Bold&lt;/b&gt;'], false)
        ->assertDontSee('<b>Bold</b>', false)
        ->assertDontSee('Visa expiry'); // empty and optional: left out
    $this->actingAs($this->leasing)->get(route('customers.edit', $customer))->assertOk()
        ->assertSee('Visa expiry')->assertDontSee('<b>Bold</b>', false);
});

test('each type is validated and tampered values give messages, never a 500', function (string $key, mixed $value, string $message) {
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => ['employer' => 'Bapco', $key => $value]]))
        ->toThrow(ValidationException::class, $message);
})->with([
    'text too long' => ['employer', str_repeat('x', 256), 'The Employer field must not be greater than 255 characters.'],
    'text as a list' => ['employer', ['a'], 'The Employer field must be a string.'],
    'required left blank' => ['employer', '   ', 'The Employer field is required.'],
    'long text too long' => ['notes_for_staff', str_repeat('x', 2001), 'The Notes for staff field must not be greater than 2000 characters.'],
    'four decimals' => ['monthly_income', '1.2345', 'Enter Monthly income as a number with up to 3 decimals.'],
    'thirteen digits' => ['monthly_income', '1234567890123', 'Enter Monthly income as a number with up to 3 decimals.'],
    'exponent' => ['monthly_income', '1e5', 'Enter Monthly income as a number with up to 3 decimals.'],
    'not a date' => ['visa_expiry', '31/02/2027', 'The Visa expiry field must match the format Y-m-d.'],
    'not yes or no' => ['has_pets', 'maybe', 'The Has pets field must be true or false.'],
    'unknown choice' => ['source', '999999', 'Choose Source from the list.'],
    'choice by word' => ['source', 'Agent', 'The Source field must be an integer.'],
]);

test('files are JPG, PNG, WebP or PDF up to 10 MB; a whole tampered section is a message; unknown keys are ignored', function () {
    $save = fn (array $custom) => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => $custom]);

    expect(fn () => $save(['employer' => 'Bapco', 'work_letter' => UploadedFile::fake()->create('virus.exe', 5)]))
        ->toThrow(ValidationException::class, 'Upload Work letter as a JPG, PNG, WebP or PDF file.')
        ->and(fn () => $save(['employer' => 'Bapco', 'work_letter' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]))
        ->toThrow(ValidationException::class, 'The Work letter field must not be greater than 10240 kilobytes.')
        ->and(fn () => $save(['employer' => 'Bapco', 'work_letter' => 'not-a-file']))->toThrow(ValidationException::class, 'The Work letter field must be a file.');
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => 'abc']))
        ->toThrow(ValidationException::class, 'The More details field must be an array.');

    $customer = $save(['employer' => 'Bapco', 'not_a_field' => 'x']);
    expect(CustomFieldValue::query()->where('entity_id', $customer->id)->count())->toBe(1);

    Livewire::actingAs($this->leasing)->test(Form::class, ['customer' => $customer])
        ->set('custom.source', ['x'])->set('custom.visa_expiry', ['y'])->call('save')
        ->assertHasErrors(['custom.source', 'custom.visa_expiry']);
});

test('a hidden choice is refused for new picks but a tenant holding it keeps it; a new required field waits for the next edit', function () {
    $customer = app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => ['employer' => 'Bapco', 'source' => (string) $this->agent->id]]);
    $this->agent->forceFill(['hidden_at' => now()])->save();

    app(SaveCustomer::class)->handle($this->leasing, $customer, [...$this->person, 'custom' => ['employer' => 'Bapco', 'source' => (string) $this->agent->id]]);
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'id_number' => '900109999',
        'custom' => ['employer' => 'Alba', 'source' => (string) $this->agent->id]]))->toThrow(ValidationException::class, 'Choose Source from the list.');
    Livewire::actingAs($this->leasing)->test(Form::class, ['customer' => $customer])->assertSee('Agent'); // still offered to the tenant holding it

    customField('customer', 'text', 'Badge number', ['required' => true]);
    expect(array_column(CustomFields::display($customer), 'text', 'label')['Badge number'])->toBe('—'); // shown missing, nothing blocked
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, $customer, [...$this->person, 'custom' => ['employer' => 'Bapco']]))
        ->toThrow(ValidationException::class, 'The Badge number field is required.');
});

test('a new file replaces the field\'s file and the old one stays among the tenant\'s documents; downloads follow the tenant\'s permissions', function () {
    $customer = app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => ['employer' => 'Bapco', 'work_letter' => UploadedFile::fake()->create('2025.pdf', 10, 'application/pdf')]]);
    app(SaveCustomer::class)->handle($this->leasing, $customer, [...$this->person, 'custom' => ['employer' => 'Bapco', 'work_letter' => UploadedFile::fake()->image('2026.jpg')]]);
    app(SaveCustomer::class)->handle($this->leasing, $customer, [...$this->person, 'custom' => ['employer' => 'Bapco', 'work_letter' => null]]); // no new file: kept

    $documents = Document::query()->where('documentable_type', $customer->getMorphClass())->where('documentable_id', $customer->id)->orderBy('id')->get();
    expect($documents->pluck('original_name')->all())->toBe(['2025.pdf', '2026.jpg'])
        ->and($documents->pluck('category')->unique()->values()->all())->toBe([DocumentCategory::CustomField])
        ->and(CustomFieldValue::query()->where('custom_field_id', $this->letter->id)->sole()->document_id)->toBe($documents[1]->id);

    $audit = Activity::query()->where('event', 'custom_fields.changed')->latest('id')->first();
    expect($audit->attribute_changes['old'])->toBe(['Work letter' => '2025.pdf'])
        ->and($audit->attribute_changes['attributes'])->toBe(['Work letter' => '2026.jpg']);

    // The panel cannot make Custom field documents itself.
    Livewire::actingAs($this->leasing)->test(Panel::class, ['documentable' => $customer])
        ->set('upload', UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'))->set('category', 'custom_field')->call('save')
        ->assertHasErrors('category');

    // Staff download: whoever can view the tenant, building scope as today.
    $building = Building::factory()->create();
    activeAgreement(['customer_id' => $customer->id], [Unit::factory()->for($building)->create()]);
    $this->actingAs($this->leasing)->get(route('documents.download', $documents[1]))->assertForbidden();
    $this->leasing->buildings()->attach($building->id);
    $this->actingAs($this->leasing)->get(route('documents.download', $documents[1]))->assertOk();
});

test('a choice id from another field is refused, and a tampered form.custom never reaches the tenant', function () {
    $other = customField('customer', 'choice', 'Region', choices: ['North']);
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => ['employer' => 'Bapco', 'source' => (string) $other->choices[0]->id]]))
        ->toThrow(ValidationException::class, 'Choose Source from the list.');

    Livewire::actingAs($this->leasing)->test(Form::class)
        ->set('form.name_en', 'Aisha Noor')->set('form.id_type', 'passport')->set('form.id_number', 'P7654321')->set('form.mobile', '+97339998877')
        ->set('form.custom', ['employer' => 'Evil', 'bogus' => 'x'])
        ->set('custom.employer', 'Bapco')
        ->call('save')->assertHasNoErrors();
    $customer = Customer::query()->where('id_number', 'P7654321')->sole();
    expect(array_column(CustomFields::display($customer), 'text', 'label')['Employer'])->toBe('Bapco');
});
