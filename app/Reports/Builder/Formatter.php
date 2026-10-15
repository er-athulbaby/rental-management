<?php

namespace App\Reports\Builder;

use Carbon\CarbonImmutable;

/** Raw SQL values to display strings (spec: money 3 dp with separators, dates dd/mm/yyyy). Excel uses raw values. */
final class Formatter
{
    public static function display(Field $field, mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }

        return match ($field->type) {
            FieldType::Money => number_format((float) $raw, 3),
            FieldType::Number => is_numeric($raw) && (float) $raw == (int) $raw ? number_format((int) $raw) : number_format((float) $raw, 3),
            FieldType::Date => CarbonImmutable::parse((string) $raw)->format('d/m/Y'),
            FieldType::Enum => $field->options[(string) $raw] ?? (string) $raw,
            FieldType::Boolean => $raw ? __('Yes') : __('No'),
            FieldType::Text => (string) $raw,
        };
    }

    /** A total's display; counts are whole numbers, everything else follows the field. */
    public static function total(?Field $field, string $fn, mixed $raw): string
    {
        if ($fn === 'count' || $field === null) {
            return number_format((int) $raw);
        }

        return $raw === null ? '—' : ($field->type === FieldType::Money || $fn === 'avg' ? number_format((float) $raw, 3) : self::display($field, $raw));
    }
}
