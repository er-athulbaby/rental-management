<?php

namespace App\Reports;

use App\Enums\ExpenseCategory;
use App\Models\CompanySetting;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec 2026-10-16 §2: every income-statement line for a scope over a period, month by month, on either basis. Both
 * statement screens and BuildingProfitability read from here, so their figures agree. Amounts are fils net of VAT
 * (expenses: their total when the company is not VAT-registered), summed in SQL on DECIMAL. Months are Asia/Bahrain
 * calendar months: TIMESTAMP columns round-trip as written (Bahrain wall-clock) while the MySQL session time_zone stays unset, so DATE_FORMAT needs no conversion.
 *
 * @phpstan-type Row array{label: string, kind: 'line'|'total'|'memo', amounts: array<string, int|null>, total: int}
 * @phpstan-type Statement array{months: list<string>, rows: array<string, Row>}
 */
final class IncomeStatement
{
    public const string COLLECTED = 'collected';

    public const string BILLED = 'billed';

    /**
     * Spec §4. $buildingIds null = every building plus the lines without a unit ("Not linked to a unit"). $ownerId
     * narrows to that owner's contracts: their leased units' income less the head lease, and their managed fees.
     *
     * @param  list<int>|null  $buildingIds
     * @return Statement
     */
    public static function company(string $from, string $to, string $basis, ?array $buildingIds = null, ?int $ownerId = null): array
    {
        $months = self::months($from, $to);
        $contracts = fn (Builder $q): Builder => $q
            ->when($buildingIds !== null, fn (Builder $w) => $w->whereIn('oc.building_id', $buildingIds))
            ->when($ownerId !== null, fn (Builder $w) => $w->where('oc.owner_id', $ownerId));

        // Company-earned tenant lines: owned units (no contract stamp) or leased-contract units; never managed, never deposits.
        $lines = fn (Builder $q): Builder => $q->leftJoin('units as u', 'u.id', '=', 'l.unit_id')
            ->leftJoin('owner_contracts as oc', 'oc.id', '=', 'l.owner_contract_id')
            ->where('l.charge_type', '<>', 'deposit')
            ->when($ownerId !== null,
                fn (Builder $w) => $w->where('oc.type', 'leased')->where('oc.owner_id', $ownerId),
                fn (Builder $w) => $w->where(fn (Builder $o) => $o->whereNull('l.owner_contract_id')->orWhere('oc.type', 'leased')))
            ->when($buildingIds !== null, fn (Builder $w) => $w->whereIn('u.building_id', $buildingIds));
        $bucket = "CASE WHEN l.unit_id IS NULL THEN 'unlinked' WHEN l.charge_type IN ('rent', 'service_charge', 'parking') THEN l.charge_type ELSE 'other_charges' END";
        $tenant = $basis === self::COLLECTED
            ? self::sums($lines(DB::table('payment_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id'))
                ->whereBetween('a.posted_at', [$from.' 00:00:00', $to.' 23:59:59']), 'a.posted_at', 'a.amount - a.tax_amount', $bucket)
            : self::billedLines($lines(DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')), $from, $to, $bucket);

        $income = [];
        foreach (['rent' => __('Rent'), 'service_charge' => __('Service charges'), 'parking' => __('Parking'),
            'other_charges' => __('Other tenant charges'), 'unlinked' => __('Not linked to a unit')] as $key => $label) {
            $income[$key] = self::row($label, $tenant[$key] ?? [], $months);
        }
        $income['management_fees'] = self::row(__('Management fees'), self::sums($contracts(DB::table('owner_charges as c')
            ->join('owner_contracts as oc', 'oc.id', '=', 'c.owner_contract_id'))->where('c.type', 'management_fee')
            ->whereBetween('c.posted_at', [$from.' 00:00:00', $to.' 23:59:59']), 'c.posted_at', 'c.net')['all'] ?? [], $months);

        $costs = ['head_lease' => self::row(__('Head lease'), self::headLease($basis, $from, $to, $contracts)['all'] ?? [], $months)];
        if ($ownerId === null) { // plan ruling 7: an owner's scope has no expense lines
            $expenses = self::sums(DB::table('expenses as e')->whereIn('e.charge_to', ['company', 'tenant'])->where('e.status', 'recorded')
                ->whereBetween('e.expense_date', [$from, $to])
                ->when($buildingIds !== null, fn (Builder $w) => $w->whereIn('e.building_id', $buildingIds)),
                'e.expense_date', CompanySetting::current()->vat_registered ? 'e.net' : 'e.total', 'e.category');
            foreach (ExpenseCategory::cases() as $category) {
                $costs['expense_'.$category->value] = self::row(__(str($category->value)->headline()->toString()), $expenses[$category->value] ?? [], $months);
            }
        }

        $rows = [...self::visible($income), 'total_income' => self::combine(__('Total income'), $months, $income),
            ...self::visible($costs), 'total_costs' => self::combine(__('Total costs'), $months, $costs)];
        $rows['result'] = self::combine(__('Result'), $months, [$rows['total_income']], [$rows['total_costs']]);

        return ['months' => $months, 'rows' => $rows];
    }

    /** @return list<string> the Y-m months from $from's month to $to's */
    public static function months(string $from, string $to): array
    {
        $months = [];
        $last = CarbonImmutable::parse($to)->startOfMonth();
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->lessThanOrEqualTo($last); $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }

    /** @param  Statement  $statement */
    public static function isEmpty(array $statement): bool
    {
        return array_filter($statement['rows'], fn (array $r) => $r['kind'] !== 'total') === [];
    }

    /**
     * Collected: head-lease payments by paid on. Billed: owner payables by due date, without cancelled ones (plan ruling 6).
     *
     * @param  Closure(Builder): Builder  $contracts  narrows the owner contracts (alias oc)
     * @return array<string, array<string, int>>
     */
    private static function headLease(string $basis, string $from, string $to, Closure $contracts): array
    {
        return $basis === self::COLLECTED
            ? self::sums($contracts(DB::table('disbursements as d')->join('owner_payables as p', 'p.id', '=', 'd.source_id')
                ->join('owner_contracts as oc', 'oc.id', '=', 'p.owner_contract_id'))
                ->where('d.source_type', 'owner_payable')->where('d.purpose', 'head_lease')->where('d.status', 'paid')
                ->whereBetween('d.paid_on', [$from, $to]), 'd.paid_on', 'd.amount')
            : self::sums($contracts(DB::table('owner_payables as p')->join('owner_contracts as oc', 'oc.id', '=', 'p.owner_contract_id'))
                ->where('p.status', '<>', 'cancelled')->whereBetween('p.due_date', [$from, $to]), 'p.due_date', 'p.amount');
    }

    /**
     * Issued lines by issue date, credit notes subtracted. $q joins invoice_lines l and invoices i.
     *
     * @param  literal-string  $key
     * @return array<string, array<string, int>>
     */
    private static function billedLines(Builder $q, string $from, string $to, string $key): array
    {
        return self::sums($q->where('i.status', 'issued')->whereBetween('i.issue_date', [$from, $to]),
            'i.issue_date', "CASE WHEN i.type = 'credit_note' THEN -l.net ELSE l.net END", $key);
    }

    /**
     * SUM($amount) grouped by $key and month. Every expression is a literal written in this class, never user input.
     *
     * @param  literal-string  $date
     * @param  literal-string  $amount
     * @param  literal-string  $key
     * @return array<string, array<string, int>> key => Y-m => fils
     */
    private static function sums(Builder $q, string $date, string $amount, string $key = "'all'"): array
    {
        $out = [];
        foreach ($q->selectRaw("{$key} as k, DATE_FORMAT({$date}, '%Y-%m') as m, SUM({$amount}) as t")->groupBy('k', 'm')->get() as $r) {
            $out[(string) $r->k][(string) $r->m] = Fils::fromDecimal((string) $r->t);
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $byMonth
     * @param  list<string>  $months
     * @param  'line'|'memo'  $kind
     * @return Row
     */
    private static function row(string $label, array $byMonth, array $months, string $kind = 'line'): array
    {
        $amounts = [];
        foreach ($months as $m) {
            $amounts[$m] = $byMonth[$m] ?? 0;
        }

        return ['label' => $label, 'kind' => $kind, 'amounts' => $amounts, 'total' => array_sum($amounts)];
    }

    /**
     * Σ $plus − Σ $minus per month; a "–" (null) counts as 0.
     *
     * @param  list<string>  $months
     * @param  array<array-key, Row>  $plus
     * @param  array<array-key, Row>  $minus
     * @return Row
     */
    private static function combine(string $label, array $months, array $plus, array $minus = []): array
    {
        $amounts = [];
        foreach ($months as $m) {
            $amounts[$m] = array_sum(array_map(fn (array $r) => $r['amounts'][$m] ?? 0, $plus))
                - array_sum(array_map(fn (array $r) => $r['amounts'][$m] ?? 0, $minus));
        }

        return ['label' => $label, 'kind' => 'total', 'amounts' => $amounts, 'total' => array_sum($amounts)];
    }

    /**
     * Spec §4: empty lines are hidden. A "–" (null) is not empty.
     *
     * @param  array<string, Row>  $rows
     * @return array<string, Row>
     */
    private static function visible(array $rows): array
    {
        return array_filter($rows, fn (array $r) => array_filter($r['amounts'], fn (?int $v) => $v !== 0) !== []);
    }
}
