<?php

namespace App\Billing;

use App\Enums\ProrationBasis;
use App\Support\Fils;
use Carbon\CarbonImmutable;

/** Spec §6.2 amounts, in integer fils. */
final class Proration
{
    /** One charge over its unit's dates ($from..$to) within one billing period. */
    public static function line(BillingPeriod $period, CarbonImmutable $from, CarbonImmutable $to, int $monthlyFils, int $months, ProrationBasis $basis): int
    {
        $from = $from->startOfDay()->max($period->start);
        $to = $to->startOfDay()->min($period->end);

        if ($from->greaterThan($to)) {
            return 0;
        }

        if ($period->regular && $from->equalTo($period->start) && $to->equalTo($period->end)) {
            return $monthlyFils * $months;
        }

        return self::partial($monthlyFils, $from, $to, $basis);
    }

    /**
     * Whole months counted from $from, then the remaining days (inclusive), for the span $from..$to.
     *
     * @return array{0: int, 1: int} [wholeMonths, days]
     */
    public static function span(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        $whole = 0;
        while ($from->addMonthsNoOverflow($whole + 1)->subDay()->lessThanOrEqualTo($to)) {
            $whole++;
        }

        $rest = $from->addMonthsNoOverflow($whole);

        return [$whole, $rest->greaterThan($to) ? 0 : (int) $rest->diffInDays($to, true) + 1];
    }

    /** Whole months counted from $from × monthly + remaining days × daily rate; rounded once. */
    public static function partial(int $monthlyFils, CarbonImmutable $from, CarbonImmutable $to, ProrationBasis $basis): int
    {
        [$whole, $days] = self::span($from, $to);

        $dayPart = match ($basis) {
            ProrationBasis::Actual365 => Fils::divRound($monthlyFils * $days * 12, 365),
            ProrationBasis::Days30 => Fils::divRound($monthlyFils * $days, 30),
        };

        return $monthlyFils * $whole + $dayPart;
    }
}
