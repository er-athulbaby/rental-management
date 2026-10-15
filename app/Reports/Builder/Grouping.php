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

    /**
     * The group keys a detail row belongs to (the `__g0`, `__g1` columns the Engine adds).
     *
     * @param  array<string, mixed>  $row
     * @return list<string|null>
     */
    public static function rowKeys(array $row): array
    {
        $keys = [];
        for ($i = 0; array_key_exists("__g{$i}", $row); $i++) {
            $keys[] = $row["__g{$i}"] === null ? null : (string) $row["__g{$i}"];
        }

        return $keys;
    }

    /**
     * @param  list<string|null>  $keys
     */
    public static function keyId(array $keys): string
    {
        return json_encode($keys, JSON_THROW_ON_ERROR);
    }

    /**
     * Detail rows indexed by their group keys (json), for the deepest-level group lookup.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    public static function rowsByGroup(array $rows): array
    {
        $by = [];
        foreach ($rows as $row) {
            $by[self::keyId(self::rowKeys($row))][] = $row;
        }

        return $by;
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
