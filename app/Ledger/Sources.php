<?php

namespace App\Ledger;

use App\Support\Fils;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * General ledger spec §4 (plan rulings 1–3 and 7): the money records the ledger reads, one source type each. A type has
 * one eligibility query, shared by live posting, catch-up, rebuild and Ledger health, and one line builder. Lines are
 * signed: + debit, − credit. A reversal type posts the exact opposite of its base type's lines.
 *
 * @phpstan-type Line array{account: int, amount: int, customer_id: int|null, owner_id: int|null, building_id: int|null, unit_id: int|null, memo: string|null}
 * @phpstan-type Built array{description: string, lines: list<Line>}
 */
final class Sources
{
    /** type => [table, own date (SQL over alias t), eligibility (SQL over t), joins payments as p] (plan ruling 3) */
    public const array TYPES = [
        'invoice' => ['invoices', 't.issue_date', "t.status = 'issued'", false],
    ];

    /** reversal type => the type whose lines it negates */
    public const array REVERSES = [
        'payment_reversal' => 'payment',
        'expense_reversal' => 'expense',
        'disbursement_reversal' => 'disbursement',
        'owner_payable_cancel' => 'owner_payable',
    ];

    /** The type's records due a journal that have none: own date from $start to $today. Selects id and own_date. */
    public static function pending(string $type, string $start, string $today): Builder
    {
        [$table, $date, $where, $payments] = self::TYPES[$type] ?? throw new LogicException("Unknown ledger source [{$type}].");

        return DB::table($table.' as t')
            ->when($payments, fn (Builder $q) => $q->join('payments as p', 'p.id', '=', 't.payment_id'))
            ->whereRaw($where)
            ->whereRaw("{$date} BETWEEN ? AND ?", [$start, $today])
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('journals as j')->where('j.source_type', $type)->whereColumn('j.source_id', 't.id'))
            ->selectRaw("t.id AS id, {$date} AS own_date");
    }

    /** @return Built */
    public static function build(string $type, int $id, Rules $rules): array
    {
        if (isset(self::REVERSES[$type])) {
            $base = self::build(self::REVERSES[$type], $id, $rules);

            return ['description' => $base['description'], 'lines' => array_map(fn (array $l) => [...$l, 'amount' => -$l['amount']], $base['lines'])];
        }

        return match ($type) {
            'invoice' => self::invoice($id, $rules),
            default => throw new LogicException("Unknown ledger source [{$type}]."),
        };
    }

    /** What a journal's source is called on screen: a source type, or the kind of a manual or opening journal. */
    public static function label(string $kindOrType): string
    {
        return match ($kindOrType) {
            'invoice' => __('Invoice'),
            'payment' => __('Receipt'),
            'payment_reversal' => __('Payment reversal'),
            'allocation' => __('Payment applied'),
            'expense' => __('Expense'),
            'expense_reversal' => __('Expense reversal'),
            'disbursement' => __('Payment out'),
            'disbursement_reversal' => __('Payment out reversal'),
            'owner_charge' => __('Owner charge'),
            'owner_payable' => __('Head-lease rent due'),
            'owner_payable_cancel' => __('Head-lease rent cancelled'),
            'manual' => __('Manual journal'),
            'opening' => __('Opening balances'),
            default => $kindOrType,
        };
    }

    /**
     * Rows 1–6: per line, receivables against the owner (managed), company income (leased or no contract), the deposit's
     * holder, or opening equity; VAT separately. A credit note posts the opposite, each line mapped as the line it credits.
     *
     * @return Built
     */
    private static function invoice(int $id, Rules $rules): array
    {
        $invoice = DB::table('invoices')->where('id', $id)->first(['type', 'number', 'customer_id']) ?? throw new LogicException("Invoice {$id} is missing.");
        $sign = $invoice->type === 'credit_note' ? -1 : 1;
        $customer = (int) $invoice->customer_id;
        $lines = [];

        foreach (DB::table('invoice_lines as l')
            ->leftJoin('invoice_lines as tl', 'tl.id', '=', 'l.credited_line_id')
            ->leftJoin('agreement_units as au', 'au.id', '=', DB::raw('COALESCE(tl.agreement_unit_id, l.agreement_unit_id)'))
            ->leftJoin('units as u', 'u.id', '=', DB::raw('COALESCE(tl.unit_id, l.unit_id, au.unit_id)'))
            ->leftJoin('owner_contracts as c', 'c.id', '=', DB::raw('COALESCE(tl.owner_contract_id, l.owner_contract_id)'))
            ->where('l.invoice_id', $id)->orderBy('l.id')
            ->get([DB::raw('COALESCE(tl.charge_type, l.charge_type) AS charge_type'), 'l.net', 'l.tax_amount', 'l.total',
                'c.type AS contract_type', 'c.deposits_held_by', 'c.owner_id', 'u.id AS unit_id', 'u.building_id']) as $row) {
            $dims = [$customer, self::id($row->owner_id), self::id($row->building_id), self::id($row->unit_id)];
            $total = $sign * self::fils($row->total);
            $lines[] = self::line($rules->account('receivables'), $total, ...$dims);

            if ($row->charge_type === 'deposit') {
                $lines[] = self::line(self::heldAccount($row->contract_type, $row->deposits_held_by, $rules), -$total, ...$dims);

                continue;
            }

            $income = match (true) {
                $row->contract_type === 'managed' => 'owner_payable',
                $row->charge_type === 'opening_balance' => 'opening_equity',
                $row->charge_type === 'rent' => 'rent_income',
                default => 'other_income',
            };
            $lines[] = self::line($rules->account($income), -$sign * self::fils($row->net), ...$dims);
            $lines[] = self::line($rules->account('vat_payable'), -$sign * self::fils($row->tax_amount), ...$dims);
        }

        return ['description' => (string) $invoice->number, 'lines' => $lines];
    }

    /** Plan ruling 3: a deposit sits with the owner only on a managed contract whose owner holds deposits. */
    private static function heldAccount(mixed $contractType, mixed $heldBy, Rules $rules): int
    {
        return $contractType === 'managed' && $heldBy === 'owner' ? $rules->account('owner_payable') : $rules->account('deposits_held');
    }

    /** @return Line */
    private static function line(int $account, int $amount, ?int $customer = null, ?int $owner = null, ?int $building = null, ?int $unit = null): array
    {
        return ['account' => $account, 'amount' => $amount, 'customer_id' => $customer, 'owner_id' => $owner, 'building_id' => $building, 'unit_id' => $unit, 'memo' => null];
    }

    private static function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function fils(mixed $decimal): int
    {
        return Fils::fromDecimal((string) $decimal);
    }
}
