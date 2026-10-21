<?php

namespace App\Enums;

/** Spec 2026-10-21 custom fields §3: the field types; each has one value column (plan ruling 1). */
enum CustomFieldType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Date = 'date';
    case Bool = 'bool';
    case Choice = 'choice';
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('Text'),
            self::LongText => __('Long text'),
            self::Number => __('Number'),
            self::Date => __('Date'),
            self::Bool => __('Yes / no'),
            self::Choice => __('Choice list'),
            self::File => __('File'),
        };
    }

    /** The custom_field_values column that holds this type. */
    public function column(): string
    {
        return match ($this) {
            self::Text, self::LongText => 'value_text',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Bool => 'value_bool',
            self::Choice => 'choice_id',
            self::File => 'document_id',
        };
    }
}
