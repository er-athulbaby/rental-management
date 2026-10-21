<?php

use App\Enums\CustomFieldEntity;
use App\Enums\DocumentCategory;
use App\Models\Customer;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a value row holds at most one value, one row per record per field, and a cleared value keeps its row', function () {
    $field = customField('customer', 'text', 'Employer');
    $customer = Customer::factory()->create();

    customValue($field, $customer, ['value_text' => 'Bapco']);
    expect(fn () => customValue($field, $customer, ['value_text' => 'Alba']))->toThrow(QueryException::class) // unique (field, record)
        ->and(fn () => DB::table('custom_field_values')->where('custom_field_id', $field->id)->update(['value_number' => '1.000']))->toThrow(QueryException::class);

    DB::table('custom_field_values')->where('custom_field_id', $field->id)->update(['value_text' => null]);
    expect(CustomFieldValue::query()->sole()->value_text)->toBeNull();
});

test('kinds and types are fixed lists, and numbers keep all 15 digits exactly', function () {
    expect(fn () => customField('invoice', 'text', 'Nope'))->toThrow(ValueError::class) // the enum refuses it before SQL
        ->and(fn () => DB::table('custom_fields')->insert(['entity' => 'invoice', 'key' => 'x', 'label_en' => 'X', 'type' => 'text', 'position' => 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('custom_fields')->insert(['entity' => 'unit', 'key' => 'f', 'label_en' => 'F', 'type' => 'formula', 'position' => 1]))->toThrow(QueryException::class);

    $field = customField('customer', 'number', 'Monthly income');
    customValue($field, Customer::factory()->create(), ['value_number' => '-123456789012.345']);
    expect(CustomFieldValue::query()->sole()->value_number)->toBe('-123456789012.345');
});

test('the key is made from the English label once: snake case, unique per kind, starting with a letter', function () {
    expect(CustomField::keyFor(CustomFieldEntity::Unit, 'Meter number'))->toBe('meter_number');

    customField('unit', 'text', 'Meter number');
    customField('unit', 'text', 'Meter number', ['archived_at' => now()]); // archived fields keep their key
    expect(CustomField::query()->where('entity', 'unit')->orderBy('id')->pluck('key')->all())->toBe(['meter_number', 'meter_number_2'])
        ->and(CustomField::keyFor(CustomFieldEntity::Unit, 'Meter Number!'))->toBe('meter_number_3')
        ->and(CustomField::keyFor(CustomFieldEntity::Building, 'Meter number'))->toBe('meter_number')
        ->and(CustomField::keyFor(CustomFieldEntity::Unit, '2nd phone'))->toBe('f_2_nd_phone')
        ->and(CustomField::keyFor(CustomFieldEntity::Unit, '???'))->toBe('field')
        ->and(strlen(CustomField::keyFor(CustomFieldEntity::Unit, str_repeat('Very long label ', 10))))->toBeLessThanOrEqual(40);
});

test('a key never exceeds 40 characters, with a collision suffix or the f_ prefix', function () {
    $long = str_repeat('a', 50);
    customField('unit', 'text', $long);
    $second = CustomField::keyFor(CustomFieldEntity::Unit, $long);
    expect(strlen($second))->toBeLessThanOrEqual(40)->and($second)->toEndWith('_2');

    $digits = '1'.str_repeat('b', 49);
    customField('unit', 'text', $digits);
    $key = CustomField::keyFor(CustomFieldEntity::Unit, $digits);
    expect(strlen(CustomField::query()->where('entity', 'unit')->orderByDesc('id')->value('key')))->toBeLessThanOrEqual(40)
        ->and($key)->toStartWith('f_1')->and(strlen($key))->toBeLessThanOrEqual(40)->and($key)->toEndWith('_2');
});

test('labels fall back to English, and documents take the "Custom field" category', function () {
    $plot = customField('owner', 'text', 'Plot number', ['label_ar' => 'رقم القسيمة']);
    $broker = customField('owner', 'choice', 'Broker', choices: [['Direct', 'مباشر'], 'Agency']);

    expect($plot->label('ar'))->toBe('رقم القسيمة')->and($plot->label('en'))->toBe('Plot number')
        ->and($broker->label('ar'))->toBe('Broker')
        ->and($broker->choices->map(fn ($c) => $c->label('ar'))->all())->toBe(['مباشر', 'Agency']);

    $document = new Document(['category' => DocumentCategory::CustomField, 'disk' => 'local', 'path' => 'documents/x.pdf',
        'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => User::factory()->create()->id]);
    $document->documentable()->associate(Customer::factory()->create());
    $document->save();

    expect($document->fresh()?->category)->toBe(DocumentCategory::CustomField)
        ->and(DocumentCategory::CustomField->label())->toBe('Custom field');
});
