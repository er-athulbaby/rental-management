<?php

namespace App\Enums;

enum PaymentFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
        };
    }

    /** {frequency} in Arabic contract text (spec §5.6). */
    public function arabic(): string
    {
        return match ($this) {
            self::Monthly => 'شهرياً',
            self::Quarterly => 'كل ثلاثة أشهر',
            self::HalfYearly => 'كل ستة أشهر',
            self::Yearly => 'سنوياً',
        };
    }

    /** {frequency} in English contract text. */
    public function english(): string
    {
        return match ($this) {
            self::Monthly => 'monthly',
            self::Quarterly => 'quarterly',
            self::HalfYearly => 'every six months',
            self::Yearly => 'yearly',
        };
    }
}
