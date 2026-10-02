<?php

namespace App\Billing;

use App\Enums\ProrationBasis;
use App\Support\Fils;

/** Spec §7.8, plan ruling 2: a payable's amount; a partial period is prorated in one exact step and rounded once. */
final class HeadLeaseAmount
{
    public static function for(BillingPeriod $period, int $rentFils, int $months, ProrationBasis $basis): int
    {
        if ($period->regular) {
            return $rentFils;
        }

        [$whole, $days] = Proration::span($period->start, $period->end);

        return match ($basis) {
            ProrationBasis::Actual365 => Fils::divRound(($whole * 365 + $days * 12) * $rentFils, 365 * $months),
            ProrationBasis::Days30 => Fils::divRound(($whole * 30 + $days) * $rentFils, 30 * $months),
        };
    }
}
