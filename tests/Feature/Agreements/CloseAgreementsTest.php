<?php

use App\Actions\Agreements\ExpireAgreements;
use App\Models\Customer;
use App\Models\DepositSettlement;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 02:00', 'Asia/Bahrain'));
    $this->make = fn (array $over = []) => activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2025-10-01', 'end_date' => '2026-09-30', ...$over], [Unit::factory()->create()]);
});

test('an overstay expires and stays expired; a moved-out one closes; a terminated one terminates', function () {
    $overstay = ($this->make)();
    $out = ($this->make)();
    $out->agreementUnits()->sole()->forceFill(['move_out_date' => '2026-09-30'])->save();
    $terminated = ($this->make)();
    $terminated->agreementUnits()->sole()->forceFill(['move_out_date' => '2026-09-30'])->save();
    DB::table('agreement_amendments')->insert(['agreement_id' => $terminated->id, 'type' => 'terminate', 'effective_date' => '2026-09-30', 'reason' => 'x',
        'status' => 'approved', 'applied_at' => now(), 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(app(ExpireAgreements::class)())->toBe(3)
        ->and($overstay->fresh()->status->value)->toBe('expired')
        ->and($out->fresh()->status->value)->toBe('closed')
        ->and($terminated->fresh()->status->value)->toBe('terminated')
        ->and(app(ExpireAgreements::class)())->toBe(0);
});

test('a unit that moved out before its end date gets its settlement the day after the end date', function () {
    $agreement = ($this->make)(['end_date' => '2026-10-05']);
    $agreement->agreementUnits()->sole()->forceFill(['move_out_date' => '2026-10-01'])->save();

    app(ExpireAgreements::class)();
    expect(DepositSettlement::count())->toBe(0); // still let until the end of today

    $this->travelTo(CarbonImmutable::parse('2026-10-06 02:00', 'Asia/Bahrain'));
    app(ExpireAgreements::class)();
    expect(DepositSettlement::sole()->agreement_id)->toBe($agreement->id)
        ->and($agreement->fresh()->status->value)->toBe('closed');

    $this->artisan('rms:agreements:expire')->assertSuccessful();
    expect(DepositSettlement::count())->toBe(1);
});
