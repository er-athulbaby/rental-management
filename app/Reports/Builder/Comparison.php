<?php

namespace App\Reports\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Spec §6: the same totals for a second period (level-1 groups, or the grand total when ungrouped).
 * Categories match by key. Date buckets match by position (1st bucket with 1st), since the two periods never share a bucket key.
 */
final class Comparison
{
    /**
     * $groupLimit caps the groups per period (null = unbounded, the Excel export). `truncated` says a period hit the cap.
     *
     * @return array{label: string, compareLabel: string, truncated: bool, rows: list<array<string, mixed>>}|null
     */
    public static function run(User $user, ReportDefinition $def, CarbonImmutable $today, ?int $groupLimit = Aggregator::GROUP_LIMIT): ?array
    {
        $range = QueryCompiler::periodRange($def, $today);
        if ($def->compare === null || $range === null) {
            return null;
        }
        $other = $def->compare === 'previous' ? Period::previous(...$range) : Period::lastYear(...$range);
        $single = $def->groups === [] ? null : ReportDefinition::fromArray([...$def->toArray(), 'groups' => [$def->groups[0]], 'chart' => null, 'compare' => null], $user);

        $collect = function (CarbonImmutable $from, CarbonImmutable $to) use ($user, $def, $single, $groupLimit): array {
            [$query, $fields] = QueryCompiler::compile($user, $def, [$from, $to]);
            if ($single === null) {
                return [[['label' => __('Total'), 'keys' => [], 'totals' => app(Engine::class)->totals($query, $fields, $def->totals)]], false];
            }
            $groups = Aggregator::groups($query, $fields, $single, $groupLimit);
            $cut = $groupLimit !== null && count($groups) > $groupLimit;

            return [$cut ? array_slice($groups, 0, $groupLimit) : $groups, $cut];
        };

        [$current, $curCut] = $collect(...$range);
        [$previous, $prevCut] = $collect(...$other);
        $truncated = $curCut || $prevCut;
        $isDate = $single !== null && Registry::get($def->subject)->fields($user, CarbonImmutable::today('Asia/Bahrain'))[$def->groups[0]['field']]->type === FieldType::Date;

        $zero = ['count' => 0];
        foreach ($def->totals as $key => $fn) {
            $zero["{$key}:{$fn}"] = $fn === 'sum' ? '0.000' : null; // a missing average, minimum or maximum stays blank
        }
        $pairs = $isDate ? self::byPosition($current, $previous) : self::byKey($current, $previous, $curCut, $prevCut);

        $rows = [];
        foreach (array_slice($pairs, 0, $groupLimit ?? PHP_INT_MAX) as [$c, $p]) {
            $any = $c ?? $p ?? ['label' => '', 'keys' => []];
            $rows[] = [
                'label' => $isDate ? __(':a vs :b', ['a' => $c['label'] ?? '—', 'b' => $p['label'] ?? '—']) : $any['label'],
                'level' => 1,
                'keys' => $any['keys'],
                'current' => array_replace($zero, $c['totals'] ?? []),
                'previous' => array_replace($zero, $p['totals'] ?? []),
            ];
        }

        return ['label' => Period::label(...$range), 'compareLabel' => Period::label(...$other), 'truncated' => $truncated || count($pairs) > ($groupLimit ?? PHP_INT_MAX), 'rows' => $rows];
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $previous
     * @return list<array{0: ?array<string, mixed>, 1: ?array<string, mixed>}>
     */
    private static function byKey(array $current, array $previous, bool $curCut, bool $prevCut): array
    {
        $id = fn (array $g) => Grouping::keyId([$g['keys'][0] ?? null]);
        $c = [];
        foreach ($current as $g) {
            $c[$id($g)] = $g;
        }
        $p = [];
        foreach ($previous as $g) {
            $p[$id($g)] = $g;
        }
        $pairs = [];
        foreach (array_unique([...array_keys($c), ...array_keys($p)]) as $k) {
            // A key missing from a capped side may simply lie past the cap: never show it as a false 0.
            if ((! isset($c[$k]) && $curCut) || (! isset($p[$k]) && $prevCut)) {
                continue;
            }
            $pairs[] = [$c[$k] ?? null, $p[$k] ?? null];
        }
        usort($pairs, function ($a, $b) {
            $x = ($a[0] ?? $a[1])['keys'][0] ?? null;
            $y = ($b[0] ?? $b[1])['keys'][0] ?? null;

            return [$x === null, (string) $x] <=> [$y === null, (string) $y];
        });

        return $pairs;
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $previous
     * @return list<array{0: ?array<string, mixed>, 1: ?array<string, mixed>}>
     */
    private static function byPosition(array $current, array $previous): array
    {
        $dated = fn (array $groups) => array_values(array_filter($groups, fn ($g) => ($g['keys'][0] ?? null) !== null));
        $nulls = fn (array $groups) => array_values(array_filter($groups, fn ($g) => ($g['keys'][0] ?? null) === null))[0] ?? null;
        $c = $dated($current);
        $p = $dated($previous);
        $pairs = [];
        for ($i = 0; $i < max(count($c), count($p)); $i++) {
            $pairs[] = [$c[$i] ?? null, $p[$i] ?? null];
        }
        if ($nulls($current) !== null || $nulls($previous) !== null) {
            $pairs[] = [$nulls($current), $nulls($previous)]; // rows with no date stay together, last
        }

        return $pairs;
    }

    /**
     * The comparison as text lines for the email: "row · total: this · previous · change (pct%)". Grouped reports show
     * the first $limit groups only; the attachment has them all.
     *
     * @param  array<string, mixed>  $cmp
     * @param  list<Field>  $columns
     * @return list<string>
     */
    public static function lines(array $cmp, array $columns, int $limit = 10): array
    {
        $byKey = collect($columns)->keyBy('key');
        $lines = [__('Comparison: :a vs :b', ['a' => $cmp['label'], 'b' => $cmp['compareLabel']])];
        foreach (array_slice($cmp['rows'], 0, $limit) as $row) {
            foreach (array_keys($row['current']) as $k) {
                $k = (string) $k;
                [$field, $fn] = array_pad(explode(':', $k, 2), 2, 'count');
                $f = $k === 'count' ? null : ($byKey[$field] ?? null);
                $name = $k === 'count' ? __('Rows') : ($f->label ?? $field).' ('.__($fn).')';
                $ch = self::change($row['current'][$k], $row['previous'][$k] ?? null);
                $change = $ch['diff'] === null ? '—' : Formatter::total($f, $fn, $ch['diff']).($ch['pct'] === null ? '' : " ({$ch['pct']}%)");
                $lines[] = "{$row['label']} · {$name}: ".Formatter::total($f, $fn, $row['current'][$k]).' · '
                    .Formatter::total($f, $fn, $row['previous'][$k] ?? null).' · '.$change;
            }
        }
        if (count($cmp['rows']) > $limit || $cmp['truncated']) {
            $lines[] = __('More groups are in the attachment.');
        }

        return $lines;
    }

    /**
     * Current minus previous in exact decimals (never floats); the percentage is blank against 0 and rounds half away from zero to 1 dp.
     * Both are blank when either side is blank (an average, minimum or maximum the period doesn't have).
     *
     * @return array{diff: ?string, pct: ?string}
     */
    public static function change(mixed $current, mixed $previous): array
    {
        if (! is_numeric($current) || ! is_numeric($previous)) {
            return ['diff' => null, 'pct' => null];
        }
        $c = (string) $current;
        $p = (string) $previous;
        $diff = bcsub($c, $p, 3);
        if (bccomp($p, '0', 3) === 0) {
            return ['diff' => $diff, 'pct' => null];
        }
        $pct = bcdiv(bcmul($diff, '100', 6), ltrim($p, '-'), 6);
        $pct = bcadd($pct, bccomp($pct, '0', 6) < 0 ? '-0.05' : '0.05', 1);

        return ['diff' => $diff, 'pct' => $pct === '-0.0' ? '0.0' : $pct];
    }
}
