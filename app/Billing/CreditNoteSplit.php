<?php

namespace App\Billing;

use App\Models\InvoiceLine;
use App\Support\Fils;

/**
 * Spec §6.5, plan ruling 1: a credit of $amount (gross) split into net and tax. A credit that settles the line — after
 * the de-allocation it causes (IssueCreditNote step 2) — takes exactly the tax not carried by the allocations left and
 * the earlier credit notes. Otherwise the cumulative split on the line's credited amount.
 */
final class CreditNoteSplit
{
    /** @return array{net: int, tax: int} */
    public static function of(int $amount, InvoiceLine $line): array
    {
        $total = Fils::fromDecimal($line->total);
        $lineTax = Fils::fromDecimal($line->tax_amount);
        $credited = Fils::fromDecimal((string) $line->credited);
        $allocated = Fils::fromDecimal((string) $line->allocated);
        $allocatedAfter = min($allocated, $total - $credited - $amount);

        if ($allocatedAfter + $credited + $amount >= $total) {
            $allocationTaxAfter = $allocatedAfter === $allocated
                ? LineTax::allocatedTax($line)
                : LineTax::allocationTarget($line, $allocatedAfter); // what ReverseAllocations will leave
            $tax = $lineTax - LineTax::creditedTax($line) - $allocationTaxAfter;
        } else {
            $tax = AllocationTax::between($credited, $credited + $amount, $lineTax, $total);
        }

        $tax = max(0, min($amount, $tax));

        return ['net' => $amount - $tax, 'tax' => $tax];
    }
}
