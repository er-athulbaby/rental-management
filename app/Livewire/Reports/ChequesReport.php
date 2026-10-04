<?php

namespace App\Livewire\Reports;

use App\Livewire\Reports\Concerns\ReportPage;
use App\Models\Cheque;
use App\Reports\Queries;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec §10: the cheque reports, which also need cheques.manage or finance.view. */
class ChequesReport extends Component
{
    use ReportPage;

    #[Url]
    public string $kind = 'today';

    protected function title(): string
    {
        return __('Cheques');
    }

    protected function permission(): bool
    {
        $user = $this->actor();

        return $user->can('reports.operational') && ($user->can('cheques.manage') || $user->can('finance.view'));
    }

    protected function dateMode(): string
    {
        return 'none';
    }

    /** @return array<string, array<array-key, string>> */
    protected function options(): array
    {
        return ['kind' => [
            'today' => __('To deposit today'),
            'week' => __('To deposit this week'),
            'bounced' => __('Bounced, awaiting action'),
            'return' => __('Held cheques to return'),
        ]];
    }

    /** @return array<string, string> */
    protected function columns(): array
    {
        return ['cheque' => __('Cheque'), 'customer' => __('Tenant'), 'bank' => __('Bank'), 'date' => __('Cheque date'), 'amount' => __('Amount')];
    }

    /** @return list<string> */
    protected function numeric(): array
    {
        return ['amount'];
    }

    /** @return list<array<string, string|int|null>> */
    protected function rows(): array
    {
        $user = $this->actor();
        $query = match ($this->kind) {
            'week' => Queries::chequesToDeposit($user, Queries::weekEnd(), Queries::weekStart()),
            'bounced' => Queries::bouncedCheques($user),
            'return' => Queries::chequesToReturn($user),
            default => Queries::chequesToDeposit($user, now('Asia/Bahrain')->toDateString()),
        };

        return array_values($query->when($this->building, fn ($q, $b) => $q->whereHas('agreement.agreementUnits.unit', fn ($u) => $u->where('building_id', $b)))
            ->with('customer:id,name_en')->orderBy('cheque_date')->orderBy('id')->get()
            ->map(fn (Cheque $c) => [
                '_url' => route('cheques.show', $c),
                'cheque' => $c->cheque_no,
                'customer' => $c->customer?->name_en,
                'bank' => $c->bank_name,
                'date' => $c->cheque_date->format('d/m/Y'),
                'amount' => $c->amount,
            ])->all());
    }
}
