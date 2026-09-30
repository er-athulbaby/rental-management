<?php

namespace App\Billing;

use App\Enums\PaymentFrequency;
use Carbon\CarbonImmutable;

/** Spec §6.2 billing periods. */
final class BillingPeriods
{
    /** @return list<BillingPeriod> */
    public static function for(CarbonImmutable $start, CarbonImmutable $end, PaymentFrequency $frequency, ?int $billingDay): array
    {
        $start = $start->startOfDay();
        $end = $end->startOfDay();
        $months = $frequency->months();
        $anchor = $billingDay === null ? $start : self::firstOnOrAfter($start, $billingDay);
        $periods = [];

        if ($anchor->greaterThan($start)) {
            $periods[] = new BillingPeriod($start, $anchor->subDay()->min($end), false);
        }

        // Period k starts at anchor + k × frequency, always counted from the anchor so month-end days clamp and recover (31 Jan → 28 Feb → 31 Mar).
        for ($k = 0; ($from = $anchor->addMonthsNoOverflow($k * $months))->lessThanOrEqualTo($end); $k++) {
            $to = $anchor->addMonthsNoOverflow(($k + 1) * $months)->subDay();
            $periods[] = $to->lessThanOrEqualTo($end)
                ? new BillingPeriod($from, $to, true)
                : new BillingPeriod($from, $end, false);
        }

        return $periods;
    }

    private static function firstOnOrAfter(CarbonImmutable $date, int $day): CarbonImmutable
    {
        $candidate = $date->setDay($day);

        return $candidate->lessThan($date) ? $date->startOfMonth()->addMonthNoOverflow()->setDay($day) : $candidate;
    }
}
