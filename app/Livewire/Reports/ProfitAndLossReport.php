<?php

namespace App\Livewire\Reports;

use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\LedgerAccount;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Livewire\Component;

/** General ledger spec §6: income and expenses for a period, from the ledger (the existing income statement stays). */
class ProfitAndLossReport extends Component implements PackageReport
{
    use ReportPage;

    protected function title(): string
    {
        return __('Profit and loss');
    }

    protected function permission(): bool
    {
        return $this->actor()->can(PermissionName::LedgerView);
    }

    protected function buildingFilter(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['code' => __('Code'), 'account' => __('Account'), 'amount' => __('Amount')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['amount'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $accounts = LedgerAccount::query()->orderBy('code')->get();
        $net = Balances::net($this->from, $this->to);
        $rows = [];
        $totals = ['income' => 0, 'expense' => 0];
        foreach ([
            [LedgerAccountType::Income, __('Income'), __('Total income')],
            [LedgerAccountType::Expense, __('Expenses'), __('Total expenses')],
        ] as [$type, $heading, $label]) {
            $rows[] = ['code' => '', 'account' => $heading, 'amount' => '', '_kind' => 'total'];
            $sum = 0;
            foreach ($accounts->where('type', $type) as $account) {
                $amount = $type->sign() * ($net[$account->id] ?? 0);
                if ($amount === 0) {
                    continue;
                }
                $rows[] = ['code' => $account->code, 'account' => $account->name(), 'amount' => Fils::toDecimal($amount)];
                $sum += $amount;
            }
            $rows[] = ['code' => '', 'account' => $label, 'amount' => Fils::toDecimal($sum), '_kind' => 'total'];
            $totals[$type->value] = $sum;
        }
        $rows[] = ['code' => '', 'account' => __('Profit for the period'), 'amount' => Fils::toDecimal($totals['income'] - $totals['expense']), '_kind' => 'total'];

        return $rows;
    }
}
