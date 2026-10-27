<?php

namespace App\Livewire\Reports;

use App\Enums\LedgerAccountType;
use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\LedgerAccount;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * General ledger spec §6: assets, liabilities and equity at a date. No year-end closing journals: equity shows the
 * profit of earlier years and of the current year, so the two sides always agree.
 */
class BalanceSheetReport extends Component implements PackageReport
{
    use ReportPage;

    protected function title(): string
    {
        return __('Balance sheet');
    }

    protected function permission(): bool
    {
        return $this->actor()->can(PermissionName::LedgerView);
    }

    protected function dateMode(): string
    {
        return 'single';
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
        $net = Balances::net(null, $this->to);
        $before = Balances::net(null, CarbonImmutable::parse($this->to)->startOfYear()->subDay()->toDateString());
        $profit = function (array $balances) use ($accounts): int {
            $sum = 0;
            foreach ($accounts as $account) {
                if (in_array($account->type, [LedgerAccountType::Income, LedgerAccountType::Expense], true)) {
                    $sum -= $balances[$account->id] ?? 0;
                }
            }

            return $sum;
        };
        $earlier = $profit($before);
        $current = $profit($net) - $earlier;

        $rows = [];
        $totals = ['asset' => 0, 'liability' => 0, 'equity' => 0];
        foreach ([
            [LedgerAccountType::Asset, __('Assets'), __('Total assets')],
            [LedgerAccountType::Liability, __('Liabilities'), __('Total liabilities')],
            [LedgerAccountType::Equity, __('Equity'), __('Total equity')],
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
            if ($type === LedgerAccountType::Equity) {
                $rows[] = ['code' => '', 'account' => __("Earlier years' profit"), 'amount' => Fils::toDecimal($earlier)];
                $rows[] = ['code' => '', 'account' => __('Current-year profit'), 'amount' => Fils::toDecimal($current)];
                $sum += $earlier + $current;
            }
            $rows[] = ['code' => '', 'account' => $label, 'amount' => Fils::toDecimal($sum), '_kind' => 'total'];
            $totals[$type->value] = $sum;
        }
        $rows[] = ['code' => '', 'account' => __('Total liabilities and equity'), 'amount' => Fils::toDecimal($totals['liability'] + $totals['equity']), '_kind' => 'total'];

        return $rows;
    }
}
