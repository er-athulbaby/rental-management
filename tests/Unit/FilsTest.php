<?php

use App\Support\Fils;

test('decimals convert to integer fils and back without floats', function () {
    expect(Fils::fromDecimal('350.5'))->toBe(350500)
        ->and(Fils::fromDecimal('0.001'))->toBe(1)
        ->and(Fils::fromDecimal('-12.345'))->toBe(-12345)
        ->and(Fils::fromDecimal('7'))->toBe(7000)
        ->and(Fils::fromDecimal(7))->toBe(7000)
        ->and(Fils::toDecimal(350500))->toBe('350.500')
        ->and(Fils::toDecimal(-5))->toBe('-0.005')
        ->and(Fils::toDecimal(0))->toBe('0.000');
});

test('more than three decimals or junk is rejected', function (string $bad) {
    expect(fn () => Fils::fromDecimal($bad))->toThrow(InvalidArgumentException::class);
})->with(['1.2345', 'abc', '', '1,000', '1e3']);

test('divRound rounds half away from zero', function () {
    expect(Fils::divRound(5, 2))->toBe(3)
        ->and(Fils::divRound(4, 2))->toBe(2)
        ->and(Fils::divRound(7, 3))->toBe(2)
        ->and(Fils::divRound(-5, 2))->toBe(-3)
        ->and(Fils::divRound(0, 7))->toBe(0);
});
