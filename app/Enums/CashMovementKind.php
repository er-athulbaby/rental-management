<?php

namespace App\Enums;

/** Ledger money screens spec §3: the three kinds of cash and bank movement. */
enum CashMovementKind: string
{
    case Transfer = 'transfer';
    case BankCharge = 'bank_charge';
    case BankInterest = 'bank_interest';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => __('Transfer between money accounts'),
            self::BankCharge => __('Bank charge'),
            self::BankInterest => __('Bank interest received'),
        };
    }
}
