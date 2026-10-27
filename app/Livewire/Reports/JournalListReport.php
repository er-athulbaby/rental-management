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

/** General ledger spec §6: every journal's lines for a period, filtered by source and account, each linking to its source. */
class JournalListReport extends Component implements PackageReport
{
    use ReportPage;

    #[Url]
    public string $source = 'all';

    #[Url]
    public string $account = 'all';

    protected function title(): string
    {
        return __('Journal list');
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
        $sources = ['all' => __('All sources'), 'manual' => Sources::label('manual'), 'opening' => Sources::label('opening')];
        foreach (array_keys(Sources::TYPES) as $type) {
            $sources[$type] = Sources::label($type);
        }

        return [
            'source' => $sources,
            'account' => ['all' => __('All accounts')] + LedgerAccount::query()->orderBy('code')->get()->mapWithKeys(fn (LedgerAccount $a) => [$a->id => $a->label()])->all(),
        ];
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['date' => __('Date'), 'journal' => __('Journal'), 'source' => __('Source'), 'description' => __('Description'), 'account' => __('Account'),
            'debit' => __('Debit'), 'credit' => __('Credit')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['debit', 'credit'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $query = Balances::lines($this->from, $this->to)
            ->when($this->account !== 'all', fn ($q) => $q->where('l.ledger_account_id', (int) $this->account))
            ->when(in_array($this->source, ['manual', 'opening'], true), fn ($q) => $q->where('j.kind', $this->source))
            ->when(! in_array($this->source, ['all', 'manual', 'opening'], true), fn ($q) => $q->where('j.source_type', $this->source));
        $rows = [];
        $debits = 0;
        $credits = 0;
        foreach ($query->get() as $line) {
            $debit = Fils::fromDecimal((string) $line->debit);
            $credit = Fils::fromDecimal((string) $line->credit);
            $debits += $debit;
            $credits += $credit;
            $rows[] = [
                '_url' => Balances::url($line),
                'date' => CarbonImmutable::parse((string) $line->date)->format('d/m/Y'),
                'journal' => (string) $line->journal,
                'source' => Sources::label((string) ($line->source_type ?? $line->kind)),
                'description' => (string) $line->description,
                'account' => $line->code.' '.(app()->getLocale() === 'ar' ? $line->name_ar : $line->name_en),
                'debit' => $debit > 0 ? Fils::toDecimal($debit) : '',
                'credit' => $credit > 0 ? Fils::toDecimal($credit) : '',
            ];
        }
        $rows[] = ['date' => '', 'journal' => '', 'source' => '', 'description' => '', 'account' => __('Total'), 'debit' => Fils::toDecimal($debits), 'credit' => Fils::toDecimal($credits), '_kind' => 'total'];

        return $rows;
    }
}
