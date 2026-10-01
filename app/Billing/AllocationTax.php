<?php

namespace App\Billing;

/**
 * Spec §7.2: an allocation's share of its line's tax. Computed on the line's cumulative allocation,
 * so a fully paid line carries exactly its tax and a reversal unwinds exactly what was taken.
 */
final class AllocationTax
{
    public static function between(int $allocatedBefore, int $allocatedAfter, int $lineTax, int $lineTotal): int
    {
        return self::cumulative($allocatedAfter, $lineTax, $lineTotal) - self::cumulative($allocatedBefore, $lineTax, $lineTotal);
    }

    private static function cumulative(int $allocated, int $lineTax, int $lineTotal): int
    {
        if ($lineTotal <= 0 || $allocated <= 0) {
            return 0;
        }

        return $allocated >= $lineTotal ? $lineTax : intdiv($allocated * $lineTax, $lineTotal);
    }
}
