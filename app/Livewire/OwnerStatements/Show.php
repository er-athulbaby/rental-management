<?php

namespace App\Livewire\OwnerStatements;

use App\Actions\OwnerStatements\SubmitOwnerStatement;
use App\Billing\OwnerStatementCalculator;
use App\Livewire\Concerns\WithActor;
use App\Models\OwnerStatement;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $statementId;

    public function mount(OwnerStatement $statement): void
    {
        abort_unless($this->actor()->can('view', $statement), 403);
        $this->statementId = $statement->id;
    }

    protected function statement(): OwnerStatement
    {
        return OwnerStatement::with(['contract.owner', 'contract.building:id,code,name'])->findOrFail($this->statementId);
    }

    public function submit(SubmitOwnerStatement $submit): void
    {
        try {
            $submit->handle($this->actor(), $this->statement());
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: __('Submitted for approval.'));
    }

    public function render(): View
    {
        $statement = $this->statement();
        $figures = OwnerStatementCalculator::compute($statement);
        $balance = $figures['opening'];

        return view('livewire.owner-statements.show', [
            'statement' => $statement,
            'figures' => $figures,
            'rows' => $figures['entries']->map(function (array $e) use (&$balance) {
                $balance += $e['amount'];

                return [...$e, 'balance' => $balance];
            }),
            'canSubmit' => $statement->status->value === 'draft' && $this->actor()->can('submit', $statement),
            'approvals' => $statement->approvals()->with(['requester:id,name', 'decider:id,name'])->latest('id')->get(),
        ])->title($statement->label());
    }
}
