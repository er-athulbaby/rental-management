<?php

namespace App\Reports\Builder;

use App\Models\User;
use Carbon\CarbonImmutable;

/** Spec §6: the same totals for a second period, matched by level-1 group key (or the grand total when ungrouped). */
final class Comparison
{
    /** @return array{label: string, compareLabel: string, rows: list<array<string, mixed>>}|null */
    public static function run(User $user, ReportDefinition $def, CarbonImmutable $today): ?array
    {
        $range = QueryCompiler::periodRange($def, $today);
        if ($def->compare === null || $range === null) {
            return null;
        }
        $other = $def->compare === 'previous' ? Period::previous(...$range) : Period::lastYear(...$range);
        $single = $def->groups === [] ? null : ReportDefinition::fromArray([...$def->toArray(), 'groups' => [$def->groups[0]], 'chart' => null, 'compare' => null], $user);

        $collect = function (CarbonImmutable $from, CarbonImmutable $to) use ($user, $def, $single): array {
            [$query, $fields] = QueryCompiler::compile($user, $def, [$from, $to]);
            if ($single === null) {
                return ['' => ['label' => __('Total'), 'keys' => [], 'totals' => app(Engine::class)->totals($query, $fields, $def->totals)]];
            }
            $out = [];
            // ponytail: unbounded group list so both periods match by key; the cap is a display concern
            foreach (Aggregator::groups($query, $fields, $single, null) as $g) {
                $out[(string) ($g['keys'][0] ?? '')] = $g;
            }

            return $out;
        };

        $current = $collect(...$range);
        $previous = $collect(...$other);
        $zero = ['count' => 0];
        foreach ($def->totals as $key => $fn) {
            $zero["{$key}:{$fn}"] = $fn === 'sum' ? '0.000' : null;
        }

        $rows = [];
        foreach (array_unique([...array_keys($current), ...array_keys($previous)]) as $k) {
            $any = $current[$k] ?? $previous[$k];
            $rows[] = [
                'label' => $any['label'],
                'level' => 1,
                'keys' => $any['keys'],
                'current' => array_replace($zero, $current[$k]['totals'] ?? []),
                'previous' => array_replace($zero, $previous[$k]['totals'] ?? []),
            ];
        }
        // Same order as the groups: by key, NULL last.
        usort($rows, fn ($a, $b) => [($a['keys'][0] ?? null) === null, (string) ($a['keys'][0] ?? '')] <=> [($b['keys'][0] ?? null) === null, (string) ($b['keys'][0] ?? '')]);

        return ['label' => Period::label(...$range), 'compareLabel' => Period::label(...$other), 'rows' => $rows];
    }

    /**
     * Current minus previous in exact decimals (never floats); the percentage is blank against 0 and rounds half away from zero to 1 dp.
     *
     * @return array{diff: string, pct: ?string}
     */
    public static function change(mixed $current, mixed $previous): array
    {
        $c = is_numeric($current) ? (string) $current : '0';
        $p = is_numeric($previous) ? (string) $previous : '0';
        $diff = bcsub($c, $p, 3);
        if (bccomp($p, '0', 3) === 0) {
            return ['diff' => $diff, 'pct' => null];
        }
        $pct = bcdiv(bcmul($diff, '100', 6), ltrim($p, '-'), 6);
        $pct = bcadd($pct, bccomp($pct, '0', 6) < 0 ? '-0.05' : '0.05', 1);

        return ['diff' => $diff, 'pct' => $pct === '-0.0' ? '0.0' : $pct];
    }
}
