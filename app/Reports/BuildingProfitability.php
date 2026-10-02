<?php

namespace App\Reports;

use App\Models\Building;
use App\Models\CompanySetting;
use App\Support\Fils;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec §10: per building, cash basis, with a billed column. Income: net-of-VAT allocations on non-deposit lines of the
 * building's owned (unstamped) and leased units + finalised management fees (net) of its managed contracts. Costs:
 * head-lease payments for its leased contracts + expenses charged to the company or to tenants (plan ruling 9).
 * Lines without a unit (some manual invoices) cannot be placed in a building and are left out.
 */
final class BuildingProfitability
{
    /** @return array{collected: int, fees: int, income: int, billed: int, head_lease: int, expenses: int, costs: int, result: int} */
    public static function for(Building $building, string $from, string $to): array
    {
        $start = $from.' 00:00:00';
        $end = $to.' 23:59:59';
        $sum = fn (Builder $q, string $expr) => Fils::fromDecimal((string) ($q->selectRaw("SUM({$expr}) as t")->value('t') ?: '0'));

        // The building's company-earned lines: unstamped (owned) or stamped with a leased contract, never deposits.
        $companyLines = fn (Builder $q) => $q->join('units as u', 'u.id', '=', 'l.unit_id')
            ->leftJoin('owner_contracts as oc', 'oc.id', '=', 'l.owner_contract_id')
            ->where('u.building_id', $building->id)->where('l.charge_type', '<>', 'deposit')
            ->where(fn ($w) => $w->whereNull('l.owner_contract_id')->orWhere('oc.type', 'leased'));

        $collected = $sum($companyLines(DB::table('payment_allocations as a')->join('invoice_lines as l', 'l.id', '=', 'a.invoice_line_id'))
            ->whereBetween('a.posted_at', [$start, $end]), 'a.amount - a.tax_amount');

        $fees = $sum(DB::table('owner_charges as c')->join('owner_contracts as oc', 'oc.id', '=', 'c.owner_contract_id')
            ->where('oc.building_id', $building->id)->where('c.type', 'management_fee')->whereBetween('c.posted_at', [$start, $end]), 'c.net');

        $billedLines = fn (bool $credit) => $sum($companyLines(DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id'))
            ->where('i.status', 'issued')->where('i.type', $credit ? '=' : '<>', 'credit_note')->whereBetween('i.issue_date', [$from, $to]), 'l.net');

        $headLease = $sum(DB::table('disbursements as d')->join('owner_payables as p', 'p.id', '=', 'd.source_id')
            ->join('owner_contracts as oc', 'oc.id', '=', 'p.owner_contract_id')
            ->where('d.source_type', 'owner_payable')->where('d.purpose', 'head_lease')->where('d.status', 'paid')
            ->where('oc.building_id', $building->id)->whereBetween('d.paid_on', [$from, $to]), 'd.amount');

        // A VAT-registered company recovers the VAT on its costs; otherwise the VAT is a cost too.
        $expenses = $sum(DB::table('expenses')->where('building_id', $building->id)->whereIn('charge_to', ['company', 'tenant'])
            ->where('status', 'recorded')->whereBetween('expense_date', [$from, $to]), CompanySetting::current()->vat_registered ? 'net' : 'total');

        $income = $collected + $fees;
        $costs = $headLease + $expenses;

        return [
            'collected' => $collected,
            'fees' => $fees,
            'income' => $income,
            'billed' => $billedLines(false) - $billedLines(true) + $fees,
            'head_lease' => $headLease,
            'expenses' => $expenses,
            'costs' => $costs,
            'result' => $income - $costs,
        ];
    }
}
