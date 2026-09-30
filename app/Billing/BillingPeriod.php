<?php

namespace App\Billing;

use Carbon\CarbonImmutable;

final readonly class BillingPeriod
{
    /** @param  bool  $regular  runs from an anchor to the day before the next anchor */
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end, public bool $regular) {}
}
