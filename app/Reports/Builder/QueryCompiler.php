<?php

namespace App\Reports\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/** Base query + building + period + filters for one definition. Values are always bound. */
final class QueryCompiler
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    public static function periodRange(ReportDefinition $def, CarbonImmutable $today): ?array
    {
        return $def->periodField === null || $def->periodPreset === null
            ? null : Period::resolve($def->periodPreset, $def->periodFrom, $def->periodTo, $today);
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $range  overrides the definition's period (comparison)
     * @return array{0: Builder, 1: array<string, Field>}
     */
    public static function compile(User $user, ReportDefinition $def, ?array $range = null): array
    {
        $subject = Registry::get($def->subject);
        $asAt = CarbonImmutable::parse($def->asAt ?? 'today', 'Asia/Bahrain')->startOfDay();
        $fields = $subject->fields($user, $asAt);
        $query = $subject->query($user, $def->buildingId, $asAt);

        $range ??= self::periodRange($def, CarbonImmutable::now('Asia/Bahrain'));
        if ($range !== null && $def->periodField !== null) {
            $query->whereRaw(Sql::raw("{$fields[$def->periodField]->sql} BETWEEN ? AND ?"), [$range[0]->toDateString(), $range[1]->toDateString()]);
        }

        foreach ($def->filters as $filter) {
            self::applyFilter($query, $fields[$filter['field']], $filter['op'], $filter['value']);
        }

        return [$query, $fields];
    }

    private static function applyFilter(Builder $query, Field $field, string $op, mixed $value): void
    {
        $sql = $field->sql;
        $list = is_array($value) ? implode(',', array_fill(0, count($value), '?')) : '';
        [$where, $bindings] = match ($op) {
            'is', 'eq', 'on' => ["{$sql} = ?", [$value]],
            'is_not' => ["({$sql} <> ? OR {$sql} IS NULL)", [$value]],
            'contains' => ["{$sql} LIKE ?", ['%'.addcslashes((string) $value, '%_\\').'%']],
            'empty' => ["({$sql} IS NULL OR {$sql} = '')", []],
            'not_empty' => ["({$sql} IS NOT NULL AND {$sql} <> '')", []],
            'in' => ["{$sql} IN ({$list})", array_values((array) $value)],
            'not_in' => ["({$sql} NOT IN ({$list}) OR {$sql} IS NULL)", array_values((array) $value)],
            'ne' => ["{$sql} <> ?", [$value]],
            'gt', 'after' => ["{$sql} > ?", [$value]],
            'gte' => ["{$sql} >= ?", [$value]],
            'lt', 'before' => ["{$sql} < ?", [$value]],
            'lte' => ["{$sql} <= ?", [$value]],
            'between' => ["{$sql} BETWEEN ? AND ?", [$value[0], $value[1]]],
            'yes' => ["{$sql} = 1", []],
            'no' => ["({$sql} = 0 OR {$sql} IS NULL)", []],
            default => throw new InvalidArgumentException("Unknown filter operator [{$op}]."),
        };
        $query->whereRaw(Sql::raw($where), $bindings);
    }
}
