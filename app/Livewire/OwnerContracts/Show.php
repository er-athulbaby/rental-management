<?php

namespace App\Livewire\OwnerContracts;

use App\Actions\OwnerContracts\RequestOwnerContractTermination;
use App\Actions\OwnerContracts\SubmitOwnerContract;
use App\Livewire\Concerns\WithActor;
use App\Models\OwnerContract;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $contractId;

    public string $terminatedOn = '';

    public string $terminationReason = '';

    public function mount(OwnerContract $contract): void
    {
        abort_unless($this->actor()->can('view', $contract), 403);
        $this->contractId = $contract->id;
    }

    protected function contract(): OwnerContract
    {
        return OwnerContract::with(['owner:id,name_en', 'building:id,code,name', 'units:id,code', 'previous:id,number', 'creator:id,name'])
            ->findOrFail($this->contractId);
    }

    public function submit(SubmitOwnerContract $submit): void
    {
        try {
            $submit->handle($this->actor(), $this->contract());
        } catch (AuthorizationException) {
            abort(403);
        }

        Flux::toast(variant: 'success', text: __('Submitted for approval.'));
    }

    public function requestTermination(RequestOwnerContractTermination $request): void
    {
        try {
            $request->handle($this->actor(), $this->contract(), $this->terminatedOn, $this->terminationReason);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            // The Action's keys → this component's properties, so errors show under the right field.
            throw ValidationException::withMessages(array_filter([
                'terminatedOn' => $e->errors()['terminated_on'] ?? [],
                'terminationReason' => $e->errors()['termination_reason'] ?? [],
                'approval' => $e->errors()['approval'] ?? [],
            ]));
        }

        $this->reset('terminatedOn', 'terminationReason');
        Flux::toast(variant: 'success', text: __('Early termination sent for approval.'));
    }

    public function render(): View
    {
        $contract = $this->contract();

        return view('livewire.owner-contracts.show', [
            'contract' => $contract,
            'canManage' => $this->actor()->can('update', $contract),
            'approvals' => $contract->approvals()->with(['requester:id,name', 'decider:id,name'])->latest('id')->get(),
        ])->title($contract->label());
    }
}
