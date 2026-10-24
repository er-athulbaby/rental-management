<?php

namespace App\Ledger;

use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

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
        'payment' => ['payments', 't.received_on', '1 = 1', false],
        'payment_reversal' => ['payments', 'DATE(t.reversed_at)', "t.status = 'reversed'", false],
        // Made with the receipt (same day it was recorded): the receipt's date, but never before the invoice it pays exists. Later (credit applied, a cut): the day it happened.
        'allocation' => ['payment_allocations', 'IF(t.amount > 0 AND DATE(t.posted_at) = DATE(p.posted_at), GREATEST(p.received_on, (SELECT i.issue_date FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id WHERE l.id = t.invoice_line_id)), DATE(t.posted_at))', '1 = 1', true],
        'expense' => ['expenses', 't.expense_date', '1 = 1', false],
        'expense_reversal' => ['expenses', 'DATE(t.reversed_at)', "t.status = 'reversed'", false],
        'disbursement' => ['disbursements', 't.paid_on', "t.status IN ('paid', 'reversed')", false],
        'disbursement_reversal' => ['disbursements', 'DATE(t.reversed_at)', "t.status = 'reversed'", false],
        'owner_charge' => ['owner_charges', 'DATE(t.posted_at)', '1 = 1', false],
        // Due today or earlier; a payable cancelled before its due date never posts. Once cancelled it never changes again,
        // so updated_at is its cancellation time.
        'owner_payable' => ['owner_payables', 't.due_date', "(t.status <> 'cancelled' OR DATE(t.updated_at) >= t.due_date)", false],
        'owner_payable_cancel' => ['owner_payables', 'DATE(t.updated_at)', "t.status = 'cancelled' AND DATE(t.updated_at) >= t.due_date", false],
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
            'payment' => self::payment($id, $rules),
            'allocation' => self::allocation($id, $rules),
            'expense' => self::expense($id, $rules),
            'disbursement' => self::disbursement($id, $rules),
            'owner_charge' => self::ownerCharge($id, $rules),
            'owner_payable' => self::ownerPayable($id, $rules),
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

    /**
     * Rows 7–9: the method's account (one line per part of a split; the deposit's holder for a deposit applied) against
     * Tenant credit. Allocations then move the credit to receivables (row 10).
     *
     * @return Built
     */
    private static function payment(int $id, Rules $rules): array
    {
        $p = DB::table('payments')->where('id', $id)->first(['number', 'customer_id', 'method', 'amount']) ?? throw new LogicException("Payment {$id} is missing.");
        $customer = (int) $p->customer_id;
        $amount = self::fils($p->amount);

        /** @var list<Line> $lines */
        $lines = match ($p->method) {
            'split' => DB::table('payment_tenders')->where('payment_id', $id)->orderBy('id')->get(['method', 'amount'])
                ->map(fn (stdClass $t) => self::line($rules->account('method.'.$t->method), self::fils($t->amount), $customer))->values()->all(),
            'deposit_applied' => DB::table('deposit_movements as m')
                ->join('deposit_settlements as s', 's.id', '=', 'm.source_id')
                ->join('agreement_units as au', 'au.id', '=', 'm.agreement_unit_id')
                ->join('units as u', 'u.id', '=', 'au.unit_id')
                ->leftJoin('owner_contracts as c', 'c.id', '=', 'm.owner_contract_id')
                ->where('m.source_type', 'deposit_settlement')->where('m.type', 'applied')->where('s.payment_id', $id)->orderBy('m.id')
                ->get(['m.amount', 'c.type AS contract_type', 'c.deposits_held_by', 'c.owner_id', 'u.id AS unit_id', 'u.building_id'])
                ->map(fn (stdClass $m) => self::line(self::heldAccount($m->contract_type, $m->deposits_held_by, $rules), -self::fils($m->amount), $customer,
                    self::id($m->owner_id), self::id($m->building_id), self::id($m->unit_id)))->values()->all(),
            default => [self::line($rules->account('method.'.$p->method), $amount, $customer)],
        };
        $lines[] = self::line($rules->account('tenant_credit'), -$amount, $customer);

        return ['description' => (string) $p->number, 'lines' => $lines];
    }

    /**
     * Row 10: a payment applied to an invoice line moves the amount from Tenant credit to receivables; a negative row (a
     * reversal or a credit note's cut) moves it back.
     *
     * @return Built
     */
    private static function allocation(int $id, Rules $rules): array
    {
        $a = DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->leftJoin('agreement_units as au', 'au.id', '=', 'l.agreement_unit_id')
            ->leftJoin('units as u', 'u.id', '=', DB::raw('COALESCE(l.unit_id, au.unit_id)'))
            ->leftJoin('owner_contracts as c', 'c.id', '=', 'a.owner_contract_id')
            ->where('a.id', $id)
            ->first(['a.amount', 'p.number AS payment', 'i.number AS invoice', 'p.customer_id', 'c.owner_id', 'u.id AS unit_id', 'u.building_id'])
            ?? throw new LogicException("Payment allocation {$id} is missing.");
        $amount = self::fils($a->amount);
        $dims = [(int) $a->customer_id, self::id($a->owner_id), self::id($a->building_id), self::id($a->unit_id)];

        return ['description' => "{$a->payment} → {$a->invoice}", 'lines' => [
            self::line($rules->account('tenant_credit'), $amount, ...$dims),
            self::line($rules->account('receivables'), -$amount, ...$dims),
        ]];
    }

    /**
     * Rows 13–15: the expense's total (VAT included, plan ruling 14) is a cost of the company (by category) or of the
     * owner, owed to the vendor. A tenant charge is the company's cost; its recharge invoice posts as an invoice.
     *
     * @return Built
     */
    private static function expense(int $id, Rules $rules): array
    {
        $e = DB::table('expenses as e')->leftJoin('owner_contracts as c', 'c.id', '=', 'e.owner_contract_id')->where('e.id', $id)
            ->first(['e.description', 'e.category', 'e.charge_to', 'e.total', 'e.building_id', 'e.unit_id', 'c.owner_id'])
            ?? throw new LogicException("Expense {$id} is missing.");
        $total = self::fils($e->total);
        $dims = [null, self::id($e->owner_id), self::id($e->building_id), self::id($e->unit_id)];
        $debit = $e->charge_to === 'owner' ? 'owner_payable' : 'category.'.$e->category;

        return ['description' => (string) $e->description, 'lines' => [
            self::line($rules->account($debit), $total, ...$dims),
            self::line($rules->account('vendor_payable'), -$total, ...$dims),
        ]];
    }

    /**
     * Rows 16–20: what the payment out settles (the owner, the tenant's credit, the deposit's holder per refunded unit, or
     * Other payments out) against the payout method's account.
     *
     * @return Built
     */
    private static function disbursement(int $id, Rules $rules): array
    {
        $d = DB::table('disbursements')->where('id', $id)->first(['number', 'payee_type', 'payee_id', 'purpose', 'amount', 'method', 'source_id'])
            ?? throw new LogicException("Payment out {$id} is missing.");
        $amount = self::fils($d->amount);
        $customer = $d->payee_type === 'customer' ? (int) $d->payee_id : null;
        $owner = $d->payee_type === 'owner' ? (int) $d->payee_id : null;
        $building = self::id(match ($d->purpose) {
            'owner_remittance' => DB::table('owner_statements as s')->join('owner_contracts as c', 'c.id', '=', 's.owner_contract_id')->where('s.id', $d->source_id)->value('c.building_id'),
            'head_lease' => DB::table('owner_payables as p')->join('owner_contracts as c', 'c.id', '=', 'p.owner_contract_id')->where('p.id', $d->source_id)->value('c.building_id'),
            default => null,
        });

        /** @var list<Line> $lines */
        $lines = match ($d->purpose) {
            'owner_remittance', 'head_lease' => [self::line($rules->account('owner_payable'), $amount, null, $owner, $building)],
            'credit_refund' => [self::line($rules->account('tenant_credit'), $amount, $customer)],
            'deposit_refund' => DB::table('deposit_movements as m')
                ->join('agreement_units as au', 'au.id', '=', 'm.agreement_unit_id')
                ->join('units as u', 'u.id', '=', 'au.unit_id')
                ->leftJoin('owner_contracts as c', 'c.id', '=', 'm.owner_contract_id')
                ->where('m.source_type', 'disbursement')->where('m.source_id', $id)->where('m.type', 'refunded')->orderBy('m.id')
                ->get(['m.amount', 'c.type AS contract_type', 'c.deposits_held_by', 'c.owner_id', 'u.id AS unit_id', 'u.building_id'])
                ->map(fn (stdClass $m) => self::line(self::heldAccount($m->contract_type, $m->deposits_held_by, $rules), -self::fils($m->amount), $customer,
                    self::id($m->owner_id), self::id($m->building_id), self::id($m->unit_id)))->values()->all(),
            default => [self::line($rules->account('other_payments_out'), $amount, $customer, $owner)],
        };
        $lines[] = self::line($rules->account('method.'.$d->method), -$amount, $customer, $owner, $building);

        return ['description' => (string) $d->number, 'lines' => $lines];
    }

    /**
     * Rows 21–22: a management fee is owed by the owner (net + VAT) as fee income and VAT; an opening balance moves
     * Opening balance equity to the owner (amount + = the company owes the owner).
     *
     * @return Built
     */
    private static function ownerCharge(int $id, Rules $rules): array
    {
        $c = DB::table('owner_charges as oc')->join('owner_contracts as c', 'c.id', '=', 'oc.owner_contract_id')->where('oc.id', $id)
            ->first(['oc.type', 'oc.net', 'oc.tax_amount', 'oc.amount', 'c.number', 'c.owner_id', 'c.building_id'])
            ?? throw new LogicException("Owner charge {$id} is missing.");
        $amount = self::fils($c->amount);
        $dims = [null, (int) $c->owner_id, (int) $c->building_id];

        $lines = $c->type === 'management_fee'
            ? [
                self::line($rules->account('owner_payable'), -$amount, ...$dims),
                self::line($rules->account('fee_income'), -self::fils($c->net), ...$dims),
                self::line($rules->account('vat_payable'), -self::fils($c->tax_amount), ...$dims),
            ]
            : [
                self::line($rules->account('opening_equity'), $amount, ...$dims),
                self::line($rules->account('owner_payable'), -$amount, ...$dims),
            ];

        return ['description' => (string) $c->number, 'lines' => $lines];
    }

    /**
     * Row 23: a leased contract's rent for a period is the company's cost and owed to the owner from its due date.
     *
     * @return Built
     */
    private static function ownerPayable(int $id, Rules $rules): array
    {
        $p = DB::table('owner_payables as p')->join('owner_contracts as c', 'c.id', '=', 'p.owner_contract_id')->where('p.id', $id)
            ->first(['p.amount', 'p.period_start', 'p.period_end', 'c.number', 'c.owner_id', 'c.building_id'])
            ?? throw new LogicException("Owner payable {$id} is missing.");
        $amount = self::fils($p->amount);
        $dims = [null, (int) $p->owner_id, (int) $p->building_id];
        $period = CarbonImmutable::parse((string) $p->period_start)->format('d/m/Y').'–'.CarbonImmutable::parse((string) $p->period_end)->format('d/m/Y');

        return ['description' => trim($p->number.' '.$period), 'lines' => [
            self::line($rules->account('lease_rent'), $amount, ...$dims),
            self::line($rules->account('owner_payable'), -$amount, ...$dims),
        ]];
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
