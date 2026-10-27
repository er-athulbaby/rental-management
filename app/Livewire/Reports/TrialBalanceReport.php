<?php

namespace App\Livewire\Reports;

use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\LedgerAccount;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Livewire\Component;

/** General ledger spec §6: every account's balance at a date, as a debit or a credit; the two totals are equal. */
class TrialBalanceReport extends Component implements PackageReport
{
    use ReportPage;

    protected function title(): string
    {
        return __('Trial balance');
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
        return ['code' => __('Code'), 'account' => __('Account'), 'debit' => __('Debit'), 'credit' => __('Credit')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['debit', 'credit'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $net = Balances::net(null, $this->to);
        $rows = [];
        $debits = 0;
        $credits = 0;
        foreach (LedgerAccount::query()->orderBy('code')->get() as $account) {
            $n = $net[$account->id] ?? 0;
            if ($n === 0) {
                continue;
            }
            $rows[] = ['code' => $account->code, 'account' => $account->name(), 'debit' => $n > 0 ? Fils::toDecimal($n) : '', 'credit' => $n < 0 ? Fils::toDecimal(-$n) : ''];
            $debits += max(0, $n);
            $credits += max(0, -$n);
        }
        $rows[] = ['code' => '', 'account' => __('Total'), 'debit' => Fils::toDecimal($debits), 'credit' => Fils::toDecimal($credits), '_kind' => 'total'];

        return $rows;
    }
}
