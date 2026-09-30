<?php

namespace App\Billing;

use App\Enums\TaxCategory;
use App\Support\Fils;

/** Spec §6.4. */
final class Tax
{
    /** @param  string  $rate  percent with up to 3 decimals, e.g. '10.00' */
    public static function amount(int $netFils, TaxCategory $category, bool $vatRegistered, string $rate): int
    {
        if (! $vatRegistered || $category !== TaxCategory::Standard) {
            return 0;
        }

        // Fils::fromDecimal reads '10.00' as 10000 thousandths, so tax = net × rate‰ / 100 000.
        return Fils::divRound($netFils * Fils::fromDecimal($rate), 100_000);
    }
}
