<?php

namespace App\Livewire\Portal\Owner;

use App\Livewire\Concerns\InPortal;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: documents staff shared with the owner (plan ruling 13). */
#[Layout('layouts::portal')]
class Documents extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.owner.documents', [
            'documents' => Document::query()->sharedWithOwner($this->ownerId())->latest('id')->get(),
        ])->title(__('Documents'));
    }
}
