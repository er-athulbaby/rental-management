<?php

namespace App\Reports\Builder;

/** The SQL a group level groups by: the field, or its date bucket. */
final class Grouping
{
    public static function expression(Field $field, ?string $bucket): string
    {
        return match ($bucket) {
            'day' => "DATE({$field->sql})",
            'month' => "DATE_FORMAT({$field->sql}, '%Y-%m')",
            'quarter' => "CONCAT(YEAR({$field->sql}), '-Q', QUARTER({$field->sql}))",
            'year' => "CAST(YEAR({$field->sql}) AS CHAR)",
            default => $field->sql,
        };
    }
}
