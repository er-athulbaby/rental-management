<?php

use App\Billing\Tax;
use App\Enums\TaxCategory;

test('standard lines are taxed at the rate, rounded half-up to the fil', function () {
    expect(Tax::amount(132_500, TaxCategory::Standard, true, '10.00'))->toBe(13_250)
        ->and(Tax::amount(105, TaxCategory::Standard, true, '10.00'))->toBe(11)
        ->and(Tax::amount(1_000, TaxCategory::Standard, true, '5.50'))->toBe(55);
});

test('other categories and unregistered companies charge no tax', function (TaxCategory $category) {
    expect(Tax::amount(132_500, $category, true, '10.00'))->toBe(0);
})->with([TaxCategory::ZeroRated, TaxCategory::Exempt, TaxCategory::OutOfScope]);

test('an unregistered company charges no tax at all', function () {
    expect(Tax::amount(132_500, TaxCategory::Standard, false, '10.00'))->toBe(0);
});
