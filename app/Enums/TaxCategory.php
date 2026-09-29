<?php

namespace App\Enums;

enum TaxCategory: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';
    case OutOfScope = 'out_of_scope';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard rated',
            self::ZeroRated => 'Zero rated',
            self::Exempt => 'Exempt',
            self::OutOfScope => 'Out of scope',
        };
    }
}
