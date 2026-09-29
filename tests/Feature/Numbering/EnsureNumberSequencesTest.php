<?php

use App\Actions\EnsureNumberSequences;
use Illuminate\Support\Facades\DB;

test('it creates one row per key with the right prefixes, once', function () {
    expect(app(EnsureNumberSequences::class)(2026))->toBe(8)
        ->and(app(EnsureNumberSequences::class)(2026))->toBe(0);

    expect(DB::table('number_sequences')->where('year', 2026)->orderBy('key')->pluck('prefix', 'key')->all())->toBe([
        'agreement' => 'AGR', 'credit_note' => 'CN', 'deposit_settlement' => 'DS', 'invoice' => 'INV',
        'owner_contract' => 'OC', 'owner_statement' => 'OS', 'payment_out' => 'PO', 'receipt' => 'RCP',
    ]);
});

test('the command defaults to next year', function () {
    $this->travelTo(now('Asia/Bahrain')->setDate(2026, 12, 1));

    $this->artisan('rms:number-sequences')->assertSuccessful();

    expect(DB::table('number_sequences')->where('year', 2027)->count())->toBe(8);
});
