<?php

namespace App\Ledger;

use App\Enums\ExpenseCategory;
use App\Enums\LedgerAccountType;
use App\Enums\PaymentMethod;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * General ledger spec §3 "Posting rules" (plan rulings 3 and 10): each rule key names one ledger account. Keys are the
 * events' accounts, `method.<payment or payout method>` and `category.<expense category>`.
 */
final class Rules
{
    /** event key => label (translated by label()) */
    public const array EVENTS = [
        'receivables' => 'Tenant receivables',
        'tenant_credit' => 'Tenant credit',
        'deposits_held' => 'Tenant deposits held',
        'owner_payable' => 'Owner payable',
        'vendor_payable' => 'Vendor payable',
        'vat_payable' => 'VAT payable',
        'rent_income' => 'Rent income',
        'other_income' => 'Other charges income',
        'fee_income' => 'Management fee income',
        'lease_rent' => 'Lease rent to owners',
        'opening_equity' => 'Opening balance equity',
        'other_payments_out' => 'Other payments out',
    ];

    /** The methods that move money. deposit_applied and split never reach a rule (plan ruling 3, rows 8–9). */
    public const array METHODS = ['cash', 'bank_transfer', 'cheque', 'card'];

    /** @param  array<string, int>  $accounts  rule key => active account id */
    private function __construct(private readonly array $accounts) {}

    /** The rules whose account is active; a rule on an inactive account is missing, so posting to it fails (spec §3). */
    public static function load(): self
    {
        $accounts = [];
        foreach (DB::table('ledger_rules as r')->join('ledger_accounts as a', 'a.id', '=', 'r.ledger_account_id')
            ->where('a.active', true)->get(['r.key', 'a.id']) as $row) {
            $accounts[(string) $row->key] = (int) $row->id;
        }

        return new self($accounts);
    }

    public function account(string $key): int
    {
        return $this->accounts[$key] ?? throw new LogicException("Ledger rule [{$key}] has no active account.");
    }

    /** @return list<string> every rule key, in screen order */
    public static function keys(): array
    {
        return [
            ...array_keys(self::EVENTS),
            ...array_map(fn (string $m) => 'method.'.$m, self::METHODS),
            ...array_map(fn (ExpenseCategory $c) => 'category.'.$c->value, ExpenseCategory::cases()),
        ];
    }

    public static function label(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'method.') => __('Paid or received by :method', ['method' => __(PaymentMethod::from(substr($key, 7))->label())]),
            str_starts_with($key, 'category.') => __('Expense: :category', ['category' => __(str(substr($key, 9))->headline()->toString())]),
            default => __(self::EVENTS[$key] ?? $key),
        };
    }

    /** The account type a rule's account must have: the reports read these accounts by type. */
    public static function type(string $key): LedgerAccountType
    {
        return match (true) {
            str_starts_with($key, 'method.'), $key === 'receivables' => LedgerAccountType::Asset,
            in_array($key, ['deposits_held', 'owner_payable', 'vendor_payable', 'tenant_credit', 'vat_payable'], true) => LedgerAccountType::Liability,
            in_array($key, ['rent_income', 'other_income', 'fee_income'], true) => LedgerAccountType::Income,
            $key === 'opening_equity' => LedgerAccountType::Equity,
            default => LedgerAccountType::Expense, // category.*, lease_rent, other_payments_out
        };
    }
}
