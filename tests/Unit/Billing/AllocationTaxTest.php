<?php

use App\Billing\AllocationTax;

test('a line paid in instalments carries exactly its tax', function () {
    // Line total 110.000 = 100.000 net + 10.000 tax, paid 33.333 + 33.333 + 43.334.
    $a = AllocationTax::between(0, 33_333, 10_000, 110_000);
    $b = AllocationTax::between(33_333, 66_666, 10_000, 110_000);
    $c = AllocationTax::between(66_666, 110_000, 10_000, 110_000);

    expect([$a, $b, $c])->toBe([3_030, 3_030, 3_940])
        ->and($a + $b + $c)->toBe(10_000);
});

test('a reversal unwinds the same tax', function () {
    $paid = AllocationTax::between(0, 50_000, 10_000, 110_000);
    $back = AllocationTax::between(50_000, 0, 10_000, 110_000);

    expect($paid)->toBe(4_545)->and($back)->toBe(-4_545);
});

test('untaxed and zero-total lines carry no tax', function () {
    expect(AllocationTax::between(0, 40_000, 0, 400_000))->toBe(0)
        ->and(AllocationTax::between(0, 0, 0, 0))->toBe(0);
});
