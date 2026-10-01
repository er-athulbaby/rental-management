<?php

namespace App\Livewire\Disbursements;

use App\Enums\DisbursementStatus;
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
    use WithActor, WithPagination;

    #[Url]
    public string $status = 'all';

    public function updating(string $property): void
    {
        if ($property === 'status') {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $rows = Disbursement::query()
            ->where(fn ($q) => $q->where('payee_type', 'owner')
                ->orWhereIn('payee_id', Customer::query()->visibleTo($this->actor())->select('id')))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->latest('id')
            ->paginate(50);

        return view('livewire.disbursements.index', ['rows' => $rows, 'statuses' => DisbursementStatus::cases()]);
    }
}
