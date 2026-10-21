<?php

namespace App\Livewire\Portal\Owner;

use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\OwnerPayableStatus;
use App\Enums\OwnerStatementStatus;
use App\Livewire\Concerns\InPortal;
use App\Models\OwnerContract;
use App\Models\OwnerStatement;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: what is owed to the owner, what was paid and is due, occupancy, and what ends soon (plan ruling 15). */
#[Layout('layouts::portal')]
class Home extends Component
{
    use InPortal;

    public function render(): View
    {
        $owner = $this->owner();
        $today = now('Asia/Bahrain')->toDateString();
        $until = now('Asia/Bahrain')->addDays(90)->toDateString();
        $contracts = OwnerContract::query()->portalFor($owner->id)->with(['building' => fn ($q) => $q->withTrashed()])->orderBy('id')->get();

        return view('livewire.portal.owner.home', [
            'name' => $owner->displayName(app()->getLocale()),
            'balances' => $contracts->filter(fn (OwnerContract $c) => $c->type === OwnerContractType::Managed)
                ->map(fn (OwnerContract $c) => ['contract' => $c, 'statement' => OwnerStatement::query()->where('owner_contract_id', $c->id)
                    ->where('status', OwnerStatementStatus::Finalised)->orderByDesc('period_start')->first()])
                ->filter(fn (array $b) => $b['statement'] !== null)->values(),
            'lastPayout' => OwnerPortal::payouts($owner->id)->orderByDesc('paid_on')->orderByDesc('id')->first(),
            'nextPayable' => OwnerPortal::payables($owner->id)->where('status', OwnerPayableStatus::Scheduled)->orderBy('due_date')->orderBy('id')->first(),
            'buildings' => OwnerPortal::buildings($owner->id)->filter(fn (array $card) => $card['managed'])->values(),
            'ending' => $contracts->filter(fn (OwnerContract $c) => $c->status === OwnerContractStatus::Active
                && $c->end_date->toDateString() >= $today && $c->end_date->toDateString() <= $until)->sortBy('end_date')->values(),
        ])->title(__('Home'));
    }
}
