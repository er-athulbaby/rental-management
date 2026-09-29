<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\NextDocumentNumber;
use App\Enums\NumberSequenceKey;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now('Asia/Bahrain')->setDate(2026, 9, 28));
    app(EnsureNumberSequences::class)(2026);
});

test('numbers are formatted and consecutive', function () {
    $next = app(NextDocumentNumber::class);

    DB::transaction(function () use ($next) {
        expect($next(NumberSequenceKey::Invoice))->toBe('INV-2026-000001')
            ->and($next(NumberSequenceKey::Invoice))->toBe('INV-2026-000002')
            ->and($next(NumberSequenceKey::Agreement))->toBe('AGR-2026-000001');
    });
});

test('it refuses when the year row is missing', function () {
    $this->travelTo(now('Asia/Bahrain')->setDate(2027, 1, 1));

    expect(fn () => DB::transaction(fn () => app(NextDocumentNumber::class)(NumberSequenceKey::Invoice)))
        ->toThrow(RuntimeException::class, 'No number_sequences row for [invoice, 2027].');
});

test('a rolled-back transaction gives its number back', function () {
    try {
        DB::transaction(function () {
            app(NextDocumentNumber::class)(NumberSequenceKey::Receipt);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(DB::transaction(fn () => app(NextDocumentNumber::class)(NumberSequenceKey::Receipt)))->toBe('RCP-2026-000001');
});

test('the year is the Bahrain year', function () {
    app(EnsureNumberSequences::class)(2027);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-12-31 21:30:00', 'UTC')); // 00:30 on 1 Jan 2027 in Bahrain

    expect(DB::transaction(fn () => app(NextDocumentNumber::class)(NumberSequenceKey::Invoice)))->toBe('INV-2027-000001');
});
