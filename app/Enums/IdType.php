<?php

namespace App\Enums;

enum IdType: string
{
    case Cpr = 'cpr';
    case Passport = 'passport';
    case Cr = 'cr';

    public function label(): string
    {
        return match ($this) {
            self::Cpr => 'CPR',
            self::Passport => 'Passport',
            self::Cr => 'CR',
        };
    }
}
