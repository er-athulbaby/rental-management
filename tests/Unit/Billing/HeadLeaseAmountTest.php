<?php

use App\Billing\BillingPeriod;
use App\Billing\HeadLeaseAmount;
use App\Enums\ProrationBasis;
use Carbon\CarbonImmutable;

$period = fn (string $from, string $to, bool $regular) => new BillingPeriod(CarbonImmutable::parse($from), CarbonImmutable::parse($to), $regular);

test('a full period is the rent amount', function () use ($period) {
    expect(HeadLeaseAmount::for($period('2026-01-01', '2026-03-31', true), 3_000_000, 3, ProrationBasis::Actual365))->toBe(3_000_000);
});

test('a partial period prorates the rent in one exact step', function () use ($period) {
    // Two whole months of a quarter: exactly two thirds.
    expect(HeadLeaseAmount::for($period('2026-07-01', '2026-08-31', false), 3_000_000, 3, ProrationBasis::Actual365))->toBe(2_000_000);
    // One month and 15 days: (365 + 180) × 3 000 000 / 1 095 = 1 493 150.68 → 1 493 151.
    expect(HeadLeaseAmount::for($period('2026-04-01', '2026-05-15', false), 3_000_000, 3, ProrationBasis::Actual365))->toBe(1_493_151);
    // 30-day basis: (30 + 15) × 3 000 000 / 90 = 1 500 000.
    expect(HeadLeaseAmount::for($period('2026-04-01', '2026-05-15', false), 3_000_000, 3, ProrationBasis::Days30))->toBe(1_500_000);
});
