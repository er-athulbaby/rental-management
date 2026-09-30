<?php

use App\Billing\BillingPeriod;
use App\Billing\Proration;
use App\Enums\ProrationBasis;
use Carbon\CarbonImmutable;

test('a full regular period is the monthly amount times its months, even in February', function () {
    $d = fn (string $date) => CarbonImmutable::parse($date);
    $feb = new BillingPeriod($d('2027-02-01'), $d('2027-02-28'), true);
    $quarter = new BillingPeriod($d('2027-01-01'), $d('2027-03-31'), true);

    expect(Proration::line($feb, $d('2026-11-01'), $d('2027-10-31'), 450_000, 1, ProrationBasis::Actual365))->toBe(450_000)
        ->and(Proration::line($quarter, $d('2026-11-01'), $d('2027-10-31'), 450_000, 3, ProrationBasis::Actual365))->toBe(1_350_000);
});

test('partial days use the daily rate of the chosen basis, rounded once', function () {
    $d = fn (string $date) => CarbonImmutable::parse($date);
    // 15 days of 450.000: actual/365 = 450 × 12 / 365 × 15 = 221.9178… → 221.918; 30-day = 225.000.
    expect(Proration::partial(450_000, $d('2026-11-01'), $d('2026-11-15'), ProrationBasis::Actual365))->toBe(221_918)
        ->and(Proration::partial(450_000, $d('2026-11-01'), $d('2026-11-15'), ProrationBasis::Days30))->toBe(225_000);
});

test('whole months count from the start, then remaining days', function () {
    $d = fn (string $date) => CarbonImmutable::parse($date);
    // 10 Oct → 9 Nov is one whole month; 10–24 Nov is 15 days.
    expect(Proration::partial(450_000, $d('2026-10-10'), $d('2026-11-24'), ProrationBasis::Actual365))->toBe(450_000 + 221_918)
        ->and(Proration::partial(450_000, $d('2026-10-01'), $d('2026-10-31'), ProrationBasis::Actual365))->toBe(450_000);
});

test('a unit inside a period is billed only for its own days', function () {
    $d = fn (string $date) => CarbonImmutable::parse($date);
    $nov = new BillingPeriod($d('2026-11-01'), $d('2026-11-30'), true);

    expect(Proration::line($nov, $d('2026-11-15'), $d('2027-10-31'), 850_000, 1, ProrationBasis::Actual365))->toBe(447_123) // 16 days
        ->and(Proration::line($nov, $d('2026-06-01'), $d('2026-11-10'), 850_000, 1, ProrationBasis::Days30))->toBe(283_333) // 10 days
        ->and(Proration::line($nov, $d('2026-12-01'), $d('2027-10-31'), 850_000, 1, ProrationBasis::Actual365))->toBe(0);
});
