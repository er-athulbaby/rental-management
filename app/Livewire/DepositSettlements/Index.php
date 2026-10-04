<?php

namespace App\Livewire\DepositSettlements;

use App\Enums\DepositSettlementStatus;
use App\Livewire\Concerns\FiltersByBuilding;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\DepositSettlement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Deposit settlements')]
class Index extends Component
{
    use FiltersByBuilding, WithActor, WithPagination;

    #[Url]
    public string $status = 'open';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $rows = DepositSettlement::query()
            ->whereIn('agreement_id', Agreement::query()->visibleTo($this->actor())->select('id'))
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', [DepositSettlementStatus::Draft, DepositSettlementStatus::PendingApproval, DepositSettlementStatus::Approved]))
            ->when(! in_array($this->status, ['open', 'all'], true), fn ($q) => $q->where('status', $this->status))
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->with('agreement.customer')
            ->latest('id')->paginate(50);

        return view('livewire.deposit-settlements.index', ['rows' => $rows, 'statuses' => DepositSettlementStatus::cases()]);
    }
}
