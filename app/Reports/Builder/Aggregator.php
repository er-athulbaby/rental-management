<?php

namespace App\Reports\Builder;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Group subtotals in one query with ROLLUP (spec §5); GROUPING() tells a subtotal row from a real NULL key. */
final class Aggregator
{
    public const int GROUP_LIMIT = 1000;

    /**
     * @param  array<string, Field>  $fields
     * @return list<array{level: int, keys: list<string|null>, label: string, totals: array<string, mixed>}>
     */
    public static function groups(Builder $query, array $fields, ReportDefinition $def): array
    {
        if ($def->groups === []) {
            return [];
        }
        $inner = $query->select(Sql::raw('1 as `_one`'));
        foreach ($def->groups as $i => $g) {
            $inner->addSelect(Sql::raw(Grouping::expression($fields[$g['field']], $g['bucket'])." as `g{$i}`"));
        }
        foreach (array_keys($def->totals) as $key) {
            $inner->addSelect(Sql::raw("{$fields[$key]->sql} as `{$key}`"));
        }

        $cols = array_map(fn ($i) => "`g{$i}`", array_keys($def->groups));
        $select = [...$cols, 'COUNT(*) as `count`'];
        foreach ($cols as $i => $col) {
            $select[] = "GROUPING({$col}) as `r{$i}`";
        }
        foreach ($def->totals as $key => $fn) {
            $select[] = Engine::aggregate($fields[$key], $fn)." as `{$key}:{$fn}`";
        }

        $rows = DB::query()->fromSub($inner, 't')
            ->select(Sql::raw('/*+ MAX_EXECUTION_TIME(30000) */ '.implode(', ', $select)))
            ->groupBy(Sql::raw(implode(', ', $cols).' WITH ROLLUP'))
            ->get();

        $out = [];
        $depth = count($cols);
        foreach ($rows as $row) {
            $row = (array) $row;
            $level = $depth;
            for ($i = $depth - 1; $i >= 0; $i--) {
                if ((int) $row["r{$i}"] === 1) {
                    $level = $i;
                }
            }
            if ($level === 0) {
                continue; // the grand total: the Engine already has it
            }
            $keyIndex = $level - 1;
            $g = $def->groups[$keyIndex];
            $totals = ['count' => (int) $row['count']];
            foreach ($def->totals as $key => $fn) {
                $totals["{$key}:{$fn}"] = $row["{$key}:{$fn}"];
            }
            $out[] = [
                'level' => $level,
                'keys' => array_map(fn ($i) => $row["g{$i}"] === null ? null : (string) $row["g{$i}"], array_slice(array_keys($def->groups), 0, $level)),
                'label' => Grouping::label($fields[$g['field']], $g['bucket'], $row["g{$keyIndex}"] === null ? null : (string) $row["g{$keyIndex}"]),
                'totals' => $totals,
            ];
        }

        // Display order: by key, NULL last; a subtotal (shorter key list) sits just before its own rows.
        usort($out, function (array $a, array $b): int {
            foreach ($a['keys'] as $i => $ka) {
                if (! array_key_exists($i, $b['keys'])) {
                    return 1;
                }
                $kb = $b['keys'][$i];
                if ($ka !== $kb) {
                    return $ka === null ? 1 : ($kb === null ? -1 : strcmp($ka, $kb));
                }
            }

            return count($a['keys']) <=> count($b['keys']);
        });

        return array_slice($out, 0, self::GROUP_LIMIT);
    }
}
