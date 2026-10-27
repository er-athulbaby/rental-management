<?php

namespace App\Livewire\Reports;

use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Ledger\Sources;
use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\LedgerAccount;
use App\Reports\Packages\PackageReport;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;

/** General ledger spec §6: one account for a period: the opening balance, each line linking to its source, a running Dr − Cr balance. */
class GeneralLedgerReport extends Component implements PackageReport
{
    use ReportPage;

    #[Url]
    public string $account = '';

    public function mount(): void
    {
        $this->account = $this->account !== '' ? $this->account : (string) (LedgerAccount::query()->orderBy('code')->value('id') ?? '');
    }

    protected function title(): string
    {
        return __('General ledger');
    }

    protected function permission(): bool
    {
        return $this->actor()->can(PermissionName::LedgerView);
    }

    protected function buildingFilter(): bool
    {
        return false;
    }

    /** @return array<string, array<array-key, string>> */
    protected function options(): array
    {
        return ['account' => LedgerAccount::query()->orderBy('code')->get()->mapWithKeys(fn (LedgerAccount $a) => [$a->id => $a->label()])->all()];
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['date' => __('Date'), 'source' => __('Source'), 'reference' => __('Reference'), 'debit' => __('Debit'), 'credit' => __('Credit'), 'balance' => __('Balance')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['debit', 'credit', 'balance'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $account = (int) $this->account;
        $balance = Balances::net(null, CarbonImmutable::parse($this->from)->subDay()->toDateString())[$account] ?? 0;
        $rows = [['date' => '', 'source' => '', 'reference' => __('Opening balance'), 'debit' => '', 'credit' => '', 'balance' => Fils::toDecimal($balance), '_kind' => 'total']];

        foreach (Balances::lines($this->from, $this->to)->where('l.ledger_account_id', $account)->get() as $line) {
            $debit = Fils::fromDecimal((string) $line->debit);
            $credit = Fils::fromDecimal((string) $line->credit);
            $balance += $debit - $credit;
            $rows[] = [
                '_url' => Balances::url($line),
                'date' => CarbonImmutable::parse((string) $line->date)->format('d/m/Y'),
                'source' => Sources::label((string) ($line->source_type ?? $line->kind)),
                'reference' => (string) $line->description,
                'debit' => $debit > 0 ? Fils::toDecimal($debit) : '',
                'credit' => $credit > 0 ? Fils::toDecimal($credit) : '',
                'balance' => Fils::toDecimal($balance),
            ];
        }
        $rows[] = ['date' => '', 'source' => '', 'reference' => __('Closing balance'), 'debit' => '', 'credit' => '', 'balance' => Fils::toDecimal($balance), '_kind' => 'total'];

        return $rows;
    }
}
