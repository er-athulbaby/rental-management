<?php

namespace App\Enums;

enum ProrationBasis: string
{
    case Actual365 = 'actual_365';
    case Days30 = 'days_30';

    public function label(): string
    {
        return match ($this) {
            self::Actual365 => 'Actual days / 365',
            self::Days30 => '30-day month',
        };
    }
}
