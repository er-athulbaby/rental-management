<?php

namespace App\Billing;

use App\Models\InvoiceLine;
use App\Support\Fils;

/**
 * Spec §6.5: a credit of $amount (gross) on a line, split into net and tax on the line's cumulative credited,
 * so crediting the line's remainder takes exactly its remaining tax.
 */
final class CreditNoteSplit
{
    /** @return array{net: int, tax: int} */
    public static function of(int $amount, InvoiceLine $line): array
    {
        $before = Fils::fromDecimal((string) $line->credited);
        $tax = AllocationTax::between($before, $before + $amount, Fils::fromDecimal($line->tax_amount), Fils::fromDecimal($line->total));

        return ['net' => $amount - $tax, 'tax' => $tax];
    }
}
