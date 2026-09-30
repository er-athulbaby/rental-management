<?php

namespace App\Livewire\Agreements;

use App\Enums\AgreementStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Agreements')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = '';

    /** "Expiring soon" is a filter, not a status (spec §5.4): 30, 60 or 90 days. */
    #[Url]
    public ?int $expiring = null;

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $today = now('Asia/Bahrain')->toDateString();

        $agreements = Agreement::query()
            ->visibleTo($this->actor())
            ->with('customer:id,name_en')
            ->withCount('agreementUnits')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when(in_array($this->expiring, [30, 60, 90], true), fn ($q) => $q
                ->where('status', AgreementStatus::Active)
                ->whereBetween('end_date', [$today, now('Asia/Bahrain')->addDays((int) $this->expiring)->toDateString()]))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->where('name_en', 'like', '%'.$this->search.'%'))))
            ->latest('id')
            ->paginate(25);

        return view('livewire.agreements.index', ['agreements' => $agreements, 'statuses' => AgreementStatus::cases()]);
    }
}
