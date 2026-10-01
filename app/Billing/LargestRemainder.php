<?php

namespace App\Billing;

/** Spec §7.2 (D10): split an amount across lines in proportion to their balances, in whole fils. */
final class LargestRemainder
{
    /**
     * @param  array<int, int>  $balances  line id => balance in fils (positive)
     * @return array<int, int> line id => fils
     */
    public static function split(int $amount, array $balances): array
    {
        $total = array_sum($balances);

        if ($amount >= $total) {
            return $balances;
        }

        if ($amount <= 0 || $total <= 0) {
            return array_map(fn () => 0, $balances);
        }

        $parts = [];
        $remainders = [];
        foreach ($balances as $id => $balance) {
            $parts[$id] = intdiv($amount * $balance, $total);
            $remainders[$id] = ($amount * $balance) % $total;
        }

        // Leftover fils, one each, to the largest remainders; ties go to the lowest line id.
        $ids = array_keys($remainders);
        usort($ids, fn (int $a, int $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        $leftover = $amount - array_sum($parts);
        foreach (array_slice($ids, 0, $leftover) as $id) {
            $parts[$id]++;
        }

        return $parts;
    }
}
