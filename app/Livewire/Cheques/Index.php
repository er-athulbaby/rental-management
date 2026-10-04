<?php

namespace App\Livewire\Cheques;

use App\Actions\Cheques\DepositCheques;
use App\Enums\AgreementStatus;
use App\Enums\ChequeStatus;
use App\Livewire\Concerns\FiltersByBuilding;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\Cheque;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Cheques')]
class Index extends Component
{
    use FiltersByBuilding, WithActor, WithPagination;

    #[Url]
    public string $status = 'held';

    #[Url]
    public string $search = '';

    /** @var list<int> */
    public array $selected = [];

    public string $depositedOn = '';

    public string $agreementSearch = '';

    public function mount(): void
    {
        $this->depositedOn = now('Asia/Bahrain')->toDateString();
    }

    /** Cheques are entered against one active agreement's open invoices: pick it, then fill them in. */
    public function startEntry(int $agreementId): void
    {
        abort_unless($this->actor()->can('manage', Cheque::class), 403);
        $agreement = $this->activeAgreements()->findOrFail($agreementId);

        $this->redirectRoute('cheques.entry', ['agreement' => $agreement->id], navigate: true);
    }

    /** @return Builder<Agreement> */
    private function activeAgreements(): Builder
    {
        return Agreement::query()->visibleTo($this->actor())->where('status', AgreementStatus::Active);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'search', 'building'], true)) {
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
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('cheque_no', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->search($this->search))))
            ->with(['customer:id,name_en', 'invoice:id,number,status'])
            ->orderBy('cheque_date')->orderBy('id')
            ->paginate(50);

        return view('livewire.cheques.index', [
            'cheques' => $cheques,
            'statuses' => ChequeStatus::cases(),
            'canManage' => $canManage = $this->actor()->can('manage', Cheque::class),
            // Type-to-search: by agreement number, or the customer's name, mobile, ID or unit code.
            'agreementResults' => $canManage && mb_strlen(trim($this->agreementSearch)) >= 2
                ? $this->activeAgreements()
                    ->where(fn ($q) => $q->where('number', 'like', '%'.trim($this->agreementSearch).'%')
                        ->orWhereHas('customer', fn ($c) => $c->search($this->agreementSearch)))
                    ->with('customer:id,name_en,mobile')->orderBy('number')->limit(8)->get(['id', 'number', 'customer_id'])
                : collect(),
        ]);
    }
}
