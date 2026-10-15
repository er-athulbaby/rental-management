<?php

use App\Reports\Builder\Period;
use Carbon\CarbonImmutable;

$today = CarbonImmutable::parse('2026-10-15', 'Asia/Bahrain'); // a Thursday

test('relative presets resolve in Bahrain with Sunday weeks', function () use ($today) {
    $r = fn (string $p) => array_map(fn ($d) => $d->toDateString(), Period::resolve($p, null, null, $today));

    expect($r('this_month'))->toBe(['2026-10-01', '2026-10-31'])
        ->and($r('last_month'))->toBe(['2026-09-01', '2026-09-30'])
        ->and($r('month_to_date'))->toBe(['2026-10-01', '2026-10-15'])
        ->and($r('this_week'))->toBe(['2026-10-11', '2026-10-17'])
        ->and($r('last_week'))->toBe(['2026-10-04', '2026-10-10'])
        ->and($r('last_7_days'))->toBe(['2026-10-09', '2026-10-15'])
        ->and($r('yesterday'))->toBe(['2026-10-14', '2026-10-14'])
        ->and($r('this_quarter'))->toBe(['2026-10-01', '2026-12-31'])
        ->and($r('last_quarter'))->toBe(['2026-07-01', '2026-09-30'])
        ->and($r('year_to_date'))->toBe(['2026-01-01', '2026-10-15'])
        ->and(array_map(fn ($d) => $d->toDateString(), Period::resolve('custom', '2026-10-01', '2026-10-15', $today)))->toBe(['2026-10-01', '2026-10-15']);
});

test('comparison periods: the same length just before, or a year earlier (29 Feb falls back to 28 Feb)', function () {
    $p = fn (array $r) => array_map(fn ($d) => $d->toDateString(), $r);

    expect($p(Period::previous(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'))))->toBe(['2026-09-01', '2026-09-30'])
        ->and($p(Period::previous(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-15'))))->toBe(['2026-09-16', '2026-09-30'])
        ->and($p(Period::lastYear(CarbonImmutable::parse('2028-02-01'), CarbonImmutable::parse('2028-02-29'))))->toBe(['2027-02-01', '2027-02-28'])
        ->and(Period::label(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')))->toBe('01/10/2026 – 31/10/2026');
});
