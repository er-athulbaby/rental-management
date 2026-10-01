<?php

use App\Billing\LargestRemainder;

test('a partial amount is split by balance, leftover fils to the largest remainders', function () {
    // 100 fils over balances 1, 1, 1 → 33.33 each: floors 33, one leftover to the lowest id among equal remainders.
    expect(LargestRemainder::split(100, [11 => 1000, 12 => 1000, 13 => 1000]))->toBe([11 => 34, 12 => 33, 13 => 33]);

    // 500 over 300 / 200 / 100 → exact 250 / 166.67 / 83.33: floors 250, 166, 83 (499); +1 to 166.67.
    expect(LargestRemainder::split(500, [1 => 300_000, 2 => 200_000, 3 => 100_000]))->toBe([1 => 250, 2 => 167, 3 => 83]);
});

test('an amount at or above the total pays every line in full', function () {
    expect(LargestRemainder::split(600, [1 => 300, 2 => 200, 3 => 100]))->toBe([1 => 300, 2 => 200, 3 => 100])
        ->and(LargestRemainder::split(999, [1 => 300, 2 => 200]))->toBe([1 => 300, 2 => 200]);
});

test('no line ever gets more than its balance and the parts sum to the amount', function () {
    $balances = [1 => 7, 2 => 1, 3 => 1, 4 => 991];
    foreach ([1, 2, 3, 5, 9, 500, 999] as $amount) {
        $split = LargestRemainder::split($amount, $balances);
        expect(array_sum($split))->toBe($amount);
        foreach ($split as $id => $part) {
            expect($part)->toBeLessThanOrEqual($balances[$id])->toBeGreaterThanOrEqual(0);
        }
    }
});

test('zero or nothing to split gives zeros', function () {
    expect(LargestRemainder::split(0, [1 => 5, 2 => 5]))->toBe([1 => 0, 2 => 0])
        ->and(LargestRemainder::split(10, []))->toBe([]);
});
