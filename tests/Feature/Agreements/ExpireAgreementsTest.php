<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Enums\AgreementStatus;
use App\Models\Unit;
use Carbon\CarbonImmutable;

test('active agreements past their end date expire at 02:00, once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 02:00', 'Asia/Bahrain'));
    $ended = activeAgreement(['start_date' => '2025-10-01', 'end_date' => '2026-10-04'], [Unit::factory()->create()]);
    $today = activeAgreement(['start_date' => '2025-10-05', 'end_date' => '2026-10-05'], [Unit::factory()->create()]);

    expect(app(ExpireAgreements::class)())->toBe(1)
        ->and(app(ExpireAgreements::class)())->toBe(0)
        ->and($ended->fresh()->status)->toBe(AgreementStatus::Expired)
        ->and($today->fresh()->status)->toBe(AgreementStatus::Active);

    $this->artisan('rms:agreements:expire')->assertSuccessful();
});
