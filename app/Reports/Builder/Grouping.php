<?php

namespace App\Reports\Builder;

use Carbon\CarbonImmutable;

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

    /** How a group key reads: month 2026-10 → "Oct 2026", quarter → "Q4 2026", enum values → their labels. */
    public static function label(Field $field, ?string $bucket, ?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }

        return match ($bucket) {
            'day' => CarbonImmutable::parse($raw)->format('d/m/Y'),
            'month' => CarbonImmutable::createFromFormat('!Y-m', $raw)?->format('M Y') ?? $raw,
            'quarter' => preg_replace('/^(\d{4})-Q(\d)$/', 'Q$2 $1', $raw) ?? $raw,
            'year' => $raw,
            default => Formatter::display($field, $raw),
        };
    }
}
