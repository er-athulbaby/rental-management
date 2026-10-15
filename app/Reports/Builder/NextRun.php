<?php

namespace App\Reports\Builder;

use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** The next due time strictly after $from, at the schedule's hour in Asia/Bahrain (spec §7). Returned in Asia/Bahrain, the app timezone, so it stores and compares as is. */
final class NextRun
{
    /** @param  ReportSchedule|array{frequency: string, day: ?int, hour: int}  $s */
    public static function after(ReportSchedule|array $s, CarbonImmutable $from): CarbonImmutable
    {
        $frequency = is_array($s) ? $s['frequency'] : $s->frequency;
        $day = is_array($s) ? $s['day'] : $s->day;
        $hour = is_array($s) ? $s['hour'] : $s->hour;
        $local = $from->timezone('Asia/Bahrain');
        if ($hour < 0 || $hour > 23) {
            throw new InvalidArgumentException(__('The hour must be between 0 and 23.'));
        }
        $candidate = $local->setTime($hour, 0);

        $next = match ($frequency) {
            'daily' => $candidate->greaterThan($local) ? $candidate : $candidate->addDay(),
            'weekly' => self::weekly($candidate, $local, $day),
            'monthly' => self::monthly($local, $day, $hour),
            default => throw new InvalidArgumentException(__('Unknown schedule frequency.')),
        };

        return $next;
    }

    private static function weekly(CarbonImmutable $candidate, CarbonImmutable $local, ?int $day): CarbonImmutable
    {
        if ($day === null || $day < 0 || $day > 6) {
            throw new InvalidArgumentException(__('The weekday must be between 0 (Sunday) and 6.'));
        }
        $shift = ($day - $candidate->dayOfWeek + 7) % 7;
        $at = $candidate->addDays($shift);

        return $at->greaterThan($local) ? $at : $at->addWeek();
    }

    private static function monthly(CarbonImmutable $local, ?int $day, int $hour): CarbonImmutable
    {
        if ($day === null || $day < 0 || $day > 31) {
            throw new InvalidArgumentException(__('The day must be 1 to 28, or 0 for the last day.'));
        }
        $day = $day > 28 ? 0 : $day; // 29-31 can't exist every month: treat as the last day
        $in = fn (CarbonImmutable $month) => ($day === 0 ? $month->endOfMonth() : $month->startOfMonth()->addDays($day - 1))->setTime($hour, 0);
        $at = $in($local->startOfMonth());

        return $at->greaterThan($local) ? $at : $in($local->startOfMonth()->addMonthNoOverflow());
    }
}
