<?php

namespace App\Enums;

enum OwnerChargeType: string
{
    case ManagementFee = 'management_fee';
    case OpeningBalance = 'opening_balance';

    public function label(): string
    {
        return match ($this) {
            self::ManagementFee => __('Management fee'),
            self::OpeningBalance => __('Opening balance'),
        };
    }
}
