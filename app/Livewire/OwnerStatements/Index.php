<?php

namespace App\Livewire\OwnerStatements;

use App\Enums\OwnerStatementStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public ?int $building = null;

    #[Url]
    public ?int $contract = null;

    public function mount(): void
    {
        abort_unless($this->actor()->can('viewAny', OwnerStatement::class), 403);
    }

    public function render(): View
    {
        $contracts = OwnerContract::visibleTo($this->actor())->select('id')
            ->when($this->building, fn ($q, $b) => $q->where('building_id', $b))
            ->when($this->contract, fn ($q, $c) => $q->whereKey($c));

        return view('livewire.owner-statements.index', [
            'statements' => OwnerStatement::query()->with(['contract:id,number,owner_id', 'contract.owner:id,name_en'])
                ->whereIn('owner_contract_id', $contracts)
                ->when($this->status, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('period_start')->orderBy('id')->paginate(25),
            'buildings' => Building::visibleTo($this->actor())->orderBy('code')->get(['id', 'code', 'name']),
            'statuses' => OwnerStatementStatus::cases(),
        ])->title(__('Owner statements'));
    }
}
