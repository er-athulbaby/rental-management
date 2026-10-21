<?php

namespace App\CustomFields;

use App\Actions\Documents\StoreDocument;
use App\Audit\Audit;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Enums\DocumentCategory;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Spec 2026-10-21 custom fields §4–§5: the one implementation of "More details" for the five record kinds. A record's
 * save action merges validation() into its own validator and calls save() inside its own transaction (plan ruling 2).
 */
final class CustomFields
{
    /** Plan ruling 1: 12 integer digits and up to 3 decimals, exactly what DECIMAL(15,3) holds. */
    public const string NUMBER_PATTERN = '/^-?\d{1,12}(\.\d{1,3})?$/';

    /** Spec §4: file fields take jpg, png, webp or pdf. */
    public const string FILE_MIMES = 'jpg,jpeg,png,webp,pdf';

    /** @return Collection<int, CustomField> the kind's active fields in order, with their choices */
    public static function active(CustomFieldEntity $entity): Collection
    {
        return CustomField::query()->where('entity', $entity)->whereNull('archived_at')->with('choices')->orderBy('position')->orderBy('id')->get();
    }

    /** @return Collection<int, CustomFieldValue> the record's values keyed by field id, with the choice and the (not deleted) document */
    public static function values(Model $record): Collection
    {
        return CustomFieldValue::query()->where('entity', CustomFieldEntity::of($record))->where('entity_id', $record->getKey())
            ->with(['choice', 'document'])->get()->keyBy('custom_field_id');
    }

    /**
     * Rules, messages and attribute names to spread into a record save's own validator. Nothing when $data has no
     * "custom" key: such a caller is not touching custom fields (plan ruling 2). The import passes $enforceRequired
     * false (spec §5).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    public static function validation(CustomFieldEntity $entity, ?Model $record, array $data, bool $enforceRequired = true): array
    {
        if (! array_key_exists('custom', $data)) {
            return [[], [], []];
        }

        $values = $record?->exists ? self::values($record) : new Collection;
        $rules = ['custom' => ['array']];
        $names = ['custom' => (string) __('More details')];
        foreach (self::active($entity) as $field) {
            $held = $values->get($field->id);
            $required = $enforceRequired && $field->required ? 'required' : 'nullable';
            $rules['custom.'.$field->key] = match ($field->type) {
                CustomFieldType::Text => ['bail', $required, 'string', 'max:255'],
                CustomFieldType::LongText => ['bail', $required, 'string', 'max:2000'],
                CustomFieldType::Number => ['bail', $required, 'regex:'.self::NUMBER_PATTERN],
                CustomFieldType::Date => ['bail', $required, 'date_format:Y-m-d'],
                CustomFieldType::Bool => ['bail', $required, 'boolean'],
                // A hidden choice is no longer offered, but a record holding it keeps it (spec §4, plan ruling 11).
                CustomFieldType::Choice => ['bail', $required, 'integer', Rule::in([
                    ...$field->choices->whereNull('hidden_at')->modelKeys(), ...array_filter([$held?->choice_id]),
                ])],
                // A record that has a file keeps it when no new one is sent (plan ruling 4).
                CustomFieldType::File => ['bail', $held?->document !== null ? 'nullable' : $required, 'file', 'mimes:'.self::FILE_MIMES, 'max:'.StoreDocument::MAX_KB],
            };
            $names['custom.'.$field->key] = $field->label();
        }

        return [$rules, [
            'custom.*.regex' => (string) __('Enter :attribute as a number with up to 3 decimals.'),
            'custom.*.in' => (string) __('Choose :attribute from the list.'),
            'custom.*.date_format' => (string) __(':attribute: enter a date as dd/mm/yyyy.'),
            'custom.*.mimes' => (string) __('Upload :attribute as a JPG, PNG, WebP or PDF file.'),
        ], $names];
    }

    /**
     * Writes the sent values of the kind's active fields in the caller's transaction (plan ruling 2). A key not sent is
     * left alone, and a blank clears. A file is stored as a "Custom field" document on the record and becomes the
     * field's file; the old one stays among the record's documents (plan ruling 4). Every change is audited once per
     * save, with old and new text (plan ruling 13).
     *
     * ponytail: a retried transaction (SaveCustomer's attempts: 3) stores a sent file again; harmless, the value points at the last one.
     *
     * @param  array<string, mixed>  $values  validated, keyed by field key
     */
    public static function save(User $actor, Model $record, array $values): void
    {
        $entity = CustomFieldEntity::of($record);
        $existing = self::values($record);
        $old = [];
        $new = [];
        foreach (self::active($entity) as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }
            $column = $field->type->column();
            $row = $existing->get($field->id);
            $value = self::clean($field, $values[$field->key]);
            if ($field->type === CustomFieldType::File) {
                if (! $value instanceof UploadedFile) {
                    continue; // no new upload: the file stays (clearing it is deleting the document, plan ruling 4)
                }
                $value = app(StoreDocument::class)->handle($actor, $record, $value, DocumentCategory::CustomField)->id;
            }
            $raw = $row?->getRawOriginal($column);
            $same = $raw === null ? $value === null : ($value !== null && (string) $raw === (string) $value);
            if ($same) {
                continue;
            }

            $before = $row === null ? null : self::text($field, $row, 'en');
            $row ??= (new CustomFieldValue)->forceFill(['custom_field_id' => $field->id, 'entity' => $entity, 'entity_id' => $record->getKey()]);
            $row->forceFill([$column => $value, 'updated_by' => $actor->id])->save();
            $row->unsetRelations();
            $old[$field->label_en] = $before;
            $new[$field->label_en] = self::text($field, $row, 'en');
        }

        if ($new !== []) {
            Audit::log('custom_fields.changed', $record, $old, $new, causer: $actor);
        }
    }

    /** A value as people read it: numbers without trailing zeros, dates dd/mm/yyyy, yes/no and choices in $locale. */
    public static function text(CustomField $field, CustomFieldValue $value, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        if ($value->getAttribute($field->type->column()) === null) {
            return null;
        }

        return match ($field->type) {
            CustomFieldType::Text, CustomFieldType::LongText => $value->value_text,
            CustomFieldType::Number => self::number((string) $value->value_number),
            CustomFieldType::Date => $value->value_date?->format('d/m/Y'),
            CustomFieldType::Bool => (string) ($value->value_bool ? __('Yes', [], $locale) : __('No', [], $locale)),
            CustomFieldType::Choice => $value->choice?->label($locale),
            CustomFieldType::File => $value->document?->original_name,
        };
    }

    /** "12.500" → "12.5", "1200.000" → "1200": the stored decimal string, trimmed, never through a float. */
    public static function number(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /** @return array<string, string|null> the form's starting values, keyed by field key; files start empty */
    public static function formValues(CustomFieldEntity $entity, ?Model $record): array
    {
        $values = $record?->exists ? self::values($record) : new Collection;
        $form = [];
        foreach (self::active($entity) as $field) {
            $value = $values->get($field->id);
            $form[$field->key] = match (true) {
                $value === null, $field->type === CustomFieldType::File => null,
                $field->type === CustomFieldType::Number => $value->value_number === null ? null : self::number($value->value_number),
                $field->type === CustomFieldType::Date => $value->value_date?->toDateString(),
                $field->type === CustomFieldType::Bool => $value->value_bool === null ? null : ($value->value_bool ? '1' : '0'),
                $field->type === CustomFieldType::Choice => $value->choice_id === null ? null : (string) $value->choice_id,
                default => $value->value_text,
            };
        }

        return $form;
    }

    /** @return array<string, Document> the current file of each active file field that has one, keyed by field key */
    public static function documents(?Model $record): array
    {
        if ($record === null || ! $record->exists) {
            return [];
        }
        $values = self::values($record);
        $documents = [];
        foreach (self::active(CustomFieldEntity::of($record)) as $field) {
            $document = $field->type === CustomFieldType::File ? $values->get($field->id)?->document : null;
            if ($document !== null) {
                $documents[$field->key] = $document;
            }
        }

        return $documents;
    }

    /**
     * The read-only "More details" of a record: active fields in order, in the viewer's language. Staff see an empty
     * required field as "—" and no empty optional one; portals ($portal) show only filled "Show in portal" fields
     * (plan rulings 8 and 12).
     *
     * @return list<array{label: string, text: string, document: Document|null}>
     */
    public static function display(Model $record, bool $portal = false): array
    {
        $values = self::values($record);
        $items = [];
        foreach (self::active(CustomFieldEntity::of($record)) as $field) {
            if ($portal && ! $field->in_portal) {
                continue;
            }
            $value = $values->get($field->id);
            $text = $value === null ? null : self::text($field, $value);
            if ($text === null && ($portal || ! $field->required)) {
                continue;
            }
            $items[] = ['label' => $field->label(), 'text' => $text ?? '—', 'document' => $field->type === CustomFieldType::File ? $value?->document : null];
        }

        return $items;
    }

    /** A validated input as its column stores it (plan ruling 1); blank is null. */
    private static function clean(CustomField $field, mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($field->type) {
            CustomFieldType::Text, CustomFieldType::LongText => trim((string) $value),
            CustomFieldType::Number => Fils::toDecimal(Fils::fromDecimal(trim((string) $value))), // exact: integer thousandths, never a float
            CustomFieldType::Date => (string) $value,
            CustomFieldType::Bool => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            CustomFieldType::Choice => (int) $value,
            CustomFieldType::File => $value,
        };
    }
}
