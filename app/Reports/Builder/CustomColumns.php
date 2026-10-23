<?php

namespace App\Reports\Builder;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use LogicException;

/**
 * Spec 2026-10-21 custom fields §5: a kind's fields as builder fields keyed "<prefix><key>". Files are not columns.
 * Each value is a correlated subquery on the unique (custom_field_id, entity_id) index, so no joins are added and
 * MAX_COLUMNS stays the only limit. An archived field keeps its key, so saved reports still run: its column is empty
 * and its label says so (plan ruling 5). The SQL embeds only our integer ids and $idSql, which subjects write.
 */
final class CustomColumns
{
    /** @return list<Field> */
    public static function fields(CustomFieldEntity $entity, string $prefix, string $section, string $idSql): array
    {
        $fields = [];
        $list = CustomField::query()->where('entity', $entity)->where('type', '!=', CustomFieldType::File)->with('choices')
            ->orderByRaw('archived_at IS NOT NULL')->orderBy('position')->orderBy('id')->get();
        foreach ($list as $field) {
            $archived = $field->archived_at !== null;
            $id = (int) $field->id;
            $column = $field->type->column();
            $sql = $archived ? 'NULL'
                : "(SELECT cfv{$id}.{$column} FROM custom_field_values cfv{$id} WHERE cfv{$id}.custom_field_id = {$id} AND cfv{$id}.entity_id = {$idSql})";
            $label = $archived ? (string) __(':label (archived)', ['label' => $field->label()]) : $field->label();
            [$type, $groupable] = match ($field->type) {
                CustomFieldType::Text, CustomFieldType::LongText => [FieldType::Text, $field->type === CustomFieldType::Text],
                CustomFieldType::Number => [FieldType::Number, false],
                CustomFieldType::Date => [FieldType::Date, true],
                CustomFieldType::Bool => [FieldType::Boolean, true],
                CustomFieldType::Choice => [FieldType::Enum, true],
                CustomFieldType::File => throw new LogicException('Files are not report columns.'),
            };
            // Hidden choices stay: records keep them (plan ruling 11).
            $options = [];
            if ($field->type === CustomFieldType::Choice) {
                foreach ($field->choices as $choice) {
                    $options[(string) $choice->id] = $choice->label();
                }
            }
            $fields[] = new Field($prefix.$field->key, $label, $section, $type, $sql, $options, $groupable, true, null, $archived);
        }

        return $fields;
    }
}
