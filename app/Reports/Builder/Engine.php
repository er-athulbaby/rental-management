<?php

namespace App\Reports\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Runs a validated definition (spec §5). Read-only; the database does all filtering, sorting and totals. */
class Engine
{
    public const int DETAIL_LIMIT = 500;

    public function run(User $user, ReportDefinition $def, ?int $limit = self::DETAIL_LIMIT): ReportResult
    {
        [$query, $fields] = QueryCompiler::compile($user, $def);
        $range = QueryCompiler::periodRange($def, CarbonImmutable::now('Asia/Bahrain'));

        $grand = $this->totals(clone $query, $fields, $def->totals);
        $groupLimit = $limit === null ? null : Aggregator::GROUP_LIMIT;
        $groups = Aggregator::groups(clone $query, $fields, $def, $groupLimit);
        $groupsTruncated = $groupLimit !== null && count($groups) > $groupLimit;
        if ($groupsTruncated) {
            $groups = array_slice($groups, 0, $groupLimit);
        }
        $rows = [];
        $truncated = false;
        if (! $def->summaryOnly) {
            $detail = (clone $query)->select(array_map(fn (string $k) => Sql::raw("{$fields[$k]->sql} as `{$k}`"), $def->columns));
            foreach ($def->groups as $i => $g) { // lets the view and Excel file each row under its group
                $detail->addSelect(Sql::raw(Grouping::expression($fields[$g['field']], $g['bucket'])." as `__g{$i}`"));
            }
            $this->order($detail, $def, $fields);
            if ($limit !== null) {
                $detail->limit($limit + 1);
            }
            $rows = array_values(array_map(fn ($r) => (array) $r, $detail->get()->all()));
            if ($limit !== null && count($rows) > $limit) {
                $truncated = true;
                array_pop($rows);
            }
        }

        return new ReportResult(
            columns: array_map(fn (string $k) => $fields[$k], $def->columns),
            rows: $rows,
            rowCount: (int) $grand['count'],
            truncated: $truncated,
            grandTotals: $grand,
            groups: $groups,
            groupsTruncated: $groupsTruncated,
            periodLabel: $range === null ? null : Period::label(...$range),
        );
    }

    /**
     * Count plus each chosen total over the whole filtered set.
     *
     * @param  array<string, Field>  $fields
     * @param  array<string, string>  $totals
     * @return array<string, mixed>
     */
    public function totals(Builder $query, array $fields, array $totals): array
    {
        $inner = $query->select(Sql::raw('1 as `_one`'));
        foreach (array_keys($totals) as $key) {
            $inner->addSelect(Sql::raw("{$fields[$key]->sql} as `{$key}`"));
        }
        $select = ['COUNT(*) as `count`'];
        foreach ($totals as $key => $fn) {
            $select[] = self::aggregate($fields[$key], $fn)." as `{$key}:{$fn}`";
        }

        return (array) DB::query()->fromSub($inner, 't')->select(Sql::raw('/*+ MAX_EXECUTION_TIME(30000) */ '.implode(', ', $select)))->first();
    }

    /** SQL for one total of a column already selected as `key` in a derived table. */
    public static function aggregate(Field $field, string $fn): string
    {
        $col = "`{$field->key}`";

        return match ($fn) {
            'sum' => "COALESCE(SUM({$col}), 0)",
            'avg' => "ROUND(AVG({$col}), 3)",
            'min' => "MIN({$col})",
            'max' => "MAX({$col})",
            default => throw new InvalidArgumentException("Unknown total [{$fn}]."),
        };
    }

    /** @param  array<string, Field>  $fields */
    private function order(Builder $query, ReportDefinition $def, array $fields): void
    {
        foreach ($def->groups as $group) {
            $expr = Grouping::expression($fields[$group['field']], $group['bucket']);
            $query->orderBy(Sql::raw("({$expr}) IS NULL")); // NULL keys last, like the groups
            $query->orderBy(Sql::raw($expr));
        }
        $query->orderBy(Sql::raw($fields[$def->sort['field']]->sql), $def->sort['dir'] === 'desc' ? 'desc' : 'asc');
    }
}
