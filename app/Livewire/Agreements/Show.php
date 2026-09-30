<?php

namespace App\Livewire\Agreements;

use App\Actions\Agreements\DeleteDraftAgreement;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use WithActor;

    #[Locked]
    public int $agreementId;

    public function mount(Agreement $agreement): void
    {
        abort_unless($this->actor()->can('view', $agreement), 403);
        $this->agreementId = $agreement->id;
    }

    protected function agreement(): Agreement
    {
        return Agreement::with(['customer', 'creator:id,name', 'agreementUnits.unit.building', 'agreementUnits.charges'])->findOrFail($this->agreementId);
    }

    public function deleteDraft(DeleteDraftAgreement $delete): void
    {
        try {
            $delete->handle($this->actor(), $this->agreement());
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->redirectRoute('agreements.index', navigate: true);
    }

    public function render(): View
    {
        $agreement = $this->agreement();

        return view('livewire.agreements.show', [
            'agreement' => $agreement,
            'canManage' => $this->actor()->can('update', $agreement),
        ])->title($agreement->label());
    }
}
