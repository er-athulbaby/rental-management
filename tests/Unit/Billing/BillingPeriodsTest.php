<?php

use App\Billing\BillingPeriod;
use App\Billing\BillingPeriods;
use App\Enums\PaymentFrequency;
use Carbon\CarbonImmutable;

function bpDate(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

/** @param list<BillingPeriod> $periods */
function spans(array $periods): array
{
    return array_map(fn (BillingPeriod $p) => $p->start->format('Y-m-d').'..'.$p->end->format('Y-m-d').($p->regular ? '' : ' partial'), $periods);
}

test('monthly from the first of the month gives twelve full periods', function () {
    $periods = BillingPeriods::for(bpDate('2026-11-01'), bpDate('2027-10-31'), PaymentFrequency::Monthly, null);

    expect($periods)->toHaveCount(12)
        ->and(spans($periods)[0])->toBe('2026-11-01..2026-11-30')
        ->and(spans($periods)[3])->toBe('2027-02-01..2027-02-28')
        ->and(spans($periods)[11])->toBe('2027-10-01..2027-10-31')
        ->and(collect($periods)->every(fn ($p) => $p->regular))->toBeTrue();
});

test('without a billing day the anchor is the start day', function () {
    $periods = BillingPeriods::for(bpDate('2026-11-15'), bpDate('2027-11-14'), PaymentFrequency::Monthly, null);

    expect($periods)->toHaveCount(12)
        ->and(spans($periods)[0])->toBe('2026-11-15..2026-12-14')
        ->and(spans($periods)[11])->toBe('2027-10-15..2027-11-14');
});

test('a billing day adds a stub at the start and a short last period', function () {
    $periods = BillingPeriods::for(bpDate('2026-11-20'), bpDate('2027-11-19'), PaymentFrequency::Monthly, 1);

    expect(spans($periods)[0])->toBe('2026-11-20..2026-11-30 partial')
        ->and(spans($periods)[1])->toBe('2026-12-01..2026-12-31')
        ->and(end($periods)->start->format('Y-m-d').'..'.end($periods)->end->format('Y-m-d'))->toBe('2027-11-01..2027-11-19')
        ->and(end($periods)->regular)->toBeFalse()
        ->and($periods)->toHaveCount(13);
});

test('month-end anchors clamp from the anchor, not from the previous period', function () {
    $periods = BillingPeriods::for(bpDate('2027-01-31'), bpDate('2027-04-30'), PaymentFrequency::Monthly, null);

    expect(spans($periods))->toBe([
        '2027-01-31..2027-02-27',
        '2027-02-28..2027-03-30',
        '2027-03-31..2027-04-29',
        '2027-04-30..2027-04-30 partial',
    ]);
});

test('quarterly periods end on the agreement end date', function () {
    expect(spans(BillingPeriods::for(bpDate('2027-01-01'), bpDate('2027-08-31'), PaymentFrequency::Quarterly, null)))->toBe([
        '2027-01-01..2027-03-31',
        '2027-04-01..2027-06-30',
        '2027-07-01..2027-08-31 partial',
    ]);
});

test('an agreement shorter than its first stub is one partial period', function () {
    expect(spans(BillingPeriods::for(bpDate('2026-11-20'), bpDate('2026-11-25'), PaymentFrequency::Monthly, 1)))->toBe(['2026-11-20..2026-11-25 partial']);
});

test('a 31st anchor lands on 29 February in a leap year', function () {
    expect(spans(BillingPeriods::for(bpDate('2028-01-31'), bpDate('2028-04-30'), PaymentFrequency::Monthly, null)))->toBe([
        '2028-01-31..2028-02-28',
        '2028-02-29..2028-03-30',
        '2028-03-31..2028-04-29',
        '2028-04-30..2028-04-30 partial',
    ]);
});

test('a billing day equal to the start day adds no stub', function () {
    $periods = BillingPeriods::for(bpDate('2026-11-15'), bpDate('2027-11-14'), PaymentFrequency::Monthly, 15);

    expect($periods)->toHaveCount(12)
        ->and(spans($periods)[0])->toBe('2026-11-15..2026-12-14')
        ->and(collect($periods)->every(fn ($p) => $p->regular))->toBeTrue();
});
