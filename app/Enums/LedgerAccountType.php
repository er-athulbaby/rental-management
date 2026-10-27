<?php

namespace App\Enums;

/** General ledger spec §3: the five account types. Assets and expenses carry debit balances; the rest credit balances. */
enum LedgerAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => __('Asset'),
            self::Liability => __('Liability'),
            self::Equity => __('Equity'),
            self::Income => __('Income'),
            self::Expense => __('Expense'),
        };
    }

    /** +1 when a debit balance is the account's normal side (assets, expenses), else −1: balance × sign() is what reports show. */
    public function sign(): int
    {
        return in_array($this, [self::Asset, self::Expense], true) ? 1 : -1;
    }
}
