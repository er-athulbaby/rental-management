<?php

namespace App\Livewire\Disbursements;

use App\Enums\DisbursementStatus;
use App\Livewire\Concerns\FiltersByBuilding;
use App\Livewire\Concerns\WithActor;
use App\Models\Customer;
use App\Models\Disbursement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Payments out')]
class Index extends Component
{
    use FiltersByBuilding, WithActor, WithPagination;

    #[Url]
    public string $status = 'all';

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'building'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $rows = Disbursement::query()
            ->where(fn ($q) => $q->where('payee_type', 'owner')
                ->orWhereIn('payee_id', Customer::query()->visibleTo($this->actor())->select('id')))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->building, fn ($q, $b) => $q->inBuilding($b))
            ->latest('id')
            ->paginate(50);

        return view('livewire.disbursements.index', ['rows' => $rows, 'statuses' => DisbursementStatus::cases()]);
    }
}
