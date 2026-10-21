<?php

namespace App\CustomFields;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldChoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-21 custom fields §5: optional cf_<key> columns on the record sheets, by the form's rules with required
 * not enforced; choices by EN or AR label; files not importable; an unknown cf_ column is an error (plan ruling 7).
 */
final class CustomFieldImport
{
    /**
     * @param  list<string>  $headers
     * @return list<string> one message per cf_ column that is not an importable active field of the kind
     */
    public static function headerProblems(CustomFieldEntity $entity, array $headers): array
    {
        $fields = CustomFields::active($entity)->keyBy('key');
        $problems = [];
        foreach ($headers as $header) {
            if (! str_starts_with($header, 'cf_')) {
                continue;
            }
            $field = $fields->get(substr($header, 3));
            if ($field === null) {
                $problems[] = (string) __('Unknown custom field column :column.', ['column' => $header]);
            } elseif ($field->type === CustomFieldType::File) {
                $problems[] = (string) __('File fields cannot be imported: :column.', ['column' => $header]);
            }
        }

        return $problems;
    }

    /**
     * Validates and saves a just-imported record's cf_ cells; a blank cell is skipped. Runs inside the row's savepoint,
     * so a bad cell fails the row and rolls the record back too.
     *
     * @param  array<string, mixed>  $row  a normalised import row
     */
    public static function save(User $actor, Model $record, array $row): void
    {
        $entity = CustomFieldEntity::of($record);
        $custom = [];
        foreach (CustomFields::active($entity) as $field) {
            $cell = $row['cf_'.$field->key] ?? null;
            if ($cell === null || $cell === '' || $field->type === CustomFieldType::File) {
                continue;
            }
            $custom[$field->key] = match ($field->type) {
                CustomFieldType::Choice => self::choice($field, (string) $cell),
                CustomFieldType::Bool => self::yesNo($field, (string) $cell),
                CustomFieldType::Date => preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', (string) $cell, $m) === 1 ? "{$m[3]}-{$m[2]}-{$m[1]}" : $cell,
                default => $cell,
            };
        }
        if ($custom === []) {
            return;
        }

        $data = ['custom' => $custom];
        [$rules, $messages, $names] = CustomFields::validation($entity, $record, $data, enforceRequired: false);
        $validated = Validator::make($data, $rules, $messages, $names)->validate();
        CustomFields::save($actor, $record, (array) $validated['custom']);
    }

    /** A visible choice by its English label (any case) or its Arabic label; its id as the form would send it. */
    private static function choice(CustomField $field, string $cell): string
    {
        $visible = $field->choices->whereNull('hidden_at');
        $match = $visible->first(fn (CustomFieldChoice $c) => strcasecmp(trim($cell), $c->label_en) === 0 || ($c->label_ar !== null && trim($cell) === $c->label_ar));

        return $match !== null ? (string) $match->id : throw ValidationException::withMessages(['cf_'.$field->key => __(':field: ":value" is not one of: :list.', [
            'field' => $field->label_en, 'value' => $cell, 'list' => $visible->pluck('label_en')->implode(', '),
        ])]);
    }

    private static function yesNo(CustomField $field, string $cell): string
    {
        return match (mb_strtolower(trim($cell))) {
            'yes', 'y', 'true', '1', 'نعم' => '1',
            'no', 'n', 'false', '0', 'لا' => '0',
            default => throw ValidationException::withMessages(['cf_'.$field->key => __(':field: enter yes or no.', ['field' => $field->label_en])]),
        };
    }
}
