<?php

namespace App\Support;

use InvalidArgumentException;

/** BHD money as integer fils (1 BHD = 1000 fils). Never floats (spec §2). */
final class Fils
{
    private const string PATTERN = '/^(-)?(\d+)(?:\.(\d{1,3}))?$/';

    public static function fromDecimal(string|int $value): int
    {
        if (is_int($value)) {
            return $value * 1000;
        }

        if (! preg_match(self::PATTERN, trim($value), $m)) {
            throw new InvalidArgumentException("Not a money amount with at most 3 decimals: [{$value}]");
        }

        $fils = ((int) $m[2]) * 1000 + (int) str_pad($m[3] ?? '', 3, '0');

        return $m[1] === '-' ? -$fils : $fils;
    }

    public static function toDecimal(int $fils): string
    {
        $sign = $fils < 0 ? '-' : '';
        $abs = abs($fils);

        return sprintf('%s%d.%03d', $sign, intdiv($abs, 1000), $abs % 1000);
    }

    /** Validation rule for a non-negative money input with at most 3 decimals. */
    public static function rule(): string
    {
        return 'regex:/^\d{1,9}(\.\d{1,3})?$/';
    }
}
