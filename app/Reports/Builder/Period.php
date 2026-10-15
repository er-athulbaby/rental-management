<?php

namespace App\Reports\Builder;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Report periods (spec §4.4, §6): Asia/Bahrain, weeks Sunday–Saturday. */
final class Period
{
    /** @return array<string, string> */
    public static function presets(): array
    {
        return [
            'today' => __('Today'), 'yesterday' => __('Yesterday'), 'this_week' => __('This week'), 'last_week' => __('Last week'),
            'last_7_days' => __('Last 7 days'), 'this_month' => __('This month'), 'last_month' => __('Last month'),
            'month_to_date' => __('Month to date'), 'this_quarter' => __('This quarter'), 'last_quarter' => __('Last quarter'),
            'this_year' => __('This year'), 'last_year' => __('Last year'), 'year_to_date' => __('Year to date'), 'custom' => __('Custom dates'),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function resolve(string $preset, ?string $from, ?string $to, CarbonImmutable $today): array
    {
        $d = $today->startOfDay();
        $week = $d->subDays($d->dayOfWeek); // dayOfWeek: 0 = Sunday

        return match ($preset) {
            'today' => [$d, $d],
            'yesterday' => [$d->subDay(), $d->subDay()],
            'this_week' => [$week, $week->addDays(6)],
            'last_week' => [$week->subWeek(), $week->subDay()],
            'last_7_days' => [$d->subDays(6), $d],
            'this_month' => [$d->startOfMonth(), $d->endOfMonth()->startOfDay()],
            'last_month' => [$d->subMonthNoOverflow()->startOfMonth(), $d->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'month_to_date' => [$d->startOfMonth(), $d],
            'this_quarter' => [$d->startOfQuarter(), $d->endOfQuarter()->startOfDay()],
            'last_quarter' => [$d->startOfQuarter()->subQuarter(), $d->startOfQuarter()->subDay()],
            'this_year' => [$d->startOfYear(), $d->endOfYear()->startOfDay()],
            'last_year' => [$d->subYear()->startOfYear(), $d->subYear()->endOfYear()->startOfDay()],
            'year_to_date' => [$d->startOfYear(), $d],
            'custom' => [CarbonImmutable::parse((string) $from, 'Asia/Bahrain'), CarbonImmutable::parse((string) $to, 'Asia/Bahrain')],
            default => throw new InvalidArgumentException("Unknown period [{$preset}]."),
        };
    }

    /**
     * Same length, immediately before. A whole calendar month compares with the whole previous month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function previous(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($from->isSameDay($from->startOfMonth()) && $to->isSameDay($to->endOfMonth()) && $from->diffInMonths($to->addDay()) >= 1) {
            $months = (int) $from->diffInMonths($to->addDay());

            return [$from->subMonthsNoOverflow($months), $from->subDay()];
        }
        $days = (int) $from->diffInDays($to) + 1;

        return [$from->subDays($days), $from->subDay()];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function lastYear(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->subYearNoOverflow(), $to->subYearNoOverflow()];
    }

    public static function label(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->format('d/m/Y').' – '.$to->format('d/m/Y');
    }
}
