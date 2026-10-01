<?php

namespace App\Livewire\Cheques;

use App\Actions\Cheques\DepositCheques;
use App\Enums\ChequeStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Cheque;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Cheques')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = 'held';

    #[Url]
    public string $search = '';

    /** @var list<int> */
    public array $selected = [];

    public string $depositedOn = '';

    public function mount(): void
    {
        $this->depositedOn = now('Asia/Bahrain')->toDateString();
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function depositSelected(DepositCheques $deposit): void
    {
        try {
            $count = $deposit->handle($this->actor(), array_map('intval', $this->selected), $this->depositedOn);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->selected = [];
        Flux::toast(variant: 'success', text: __(':n cheque(s) deposited.', ['n' => $count]));
    }

    public function render(): View
    {
        $cheques = Cheque::query()->visibleTo($this->actor())
            ->where('direction', 'received')
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('cheque_no', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', '%'.$this->search.'%'))))
            ->with(['customer:id,name_en', 'invoice:id,number,status'])
            ->orderBy('cheque_date')->orderBy('id')
            ->paginate(50);

        return view('livewire.cheques.index', [
            'cheques' => $cheques,
            'statuses' => ChequeStatus::cases(),
            'canManage' => $this->actor()->can('manage', Cheque::class),
        ]);
    }
}
