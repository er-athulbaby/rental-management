<?php

namespace App\Reports;

use App\Models\Building;

/**
 * Spec §10 (v1) and 2026-10-16 §6: the quick per-building summary, cash basis with a billed column. Every figure comes
 * from IncomeStatement (one building), so it always agrees with the income statement. Lines without a unit cannot be
 * placed in a building and are left out.
 */
final class BuildingProfitability
{
    /** @return array{collected: int, fees: int, income: int, billed: int, head_lease: int, expenses: int, costs: int, result: int} */
    public static function for(Building $building, string $from, string $to): array
    {
        $collected = IncomeStatement::company($from, $to, IncomeStatement::COLLECTED, [$building->id])['rows'];
        $billed = IncomeStatement::company($from, $to, IncomeStatement::BILLED, [$building->id])['rows'];
        $fees = $collected['management_fees']['total'] ?? 0;
        $headLease = $collected['head_lease']['total'] ?? 0;
        $income = $collected['total_income']['total'];
        $costs = $collected['total_costs']['total'];

        return [
            'collected' => $income - $fees,
            'fees' => $fees,
            'income' => $income,
            'billed' => $billed['total_income']['total'], // billed tenant lines + the fees, as before
            'head_lease' => $headLease,
            'expenses' => $costs - $headLease,
            'costs' => $costs,
            'result' => $income - $costs,
        ];
    }
}
