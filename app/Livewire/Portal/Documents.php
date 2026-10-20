<?php

namespace App\Livewire\Portal;

use App\Livewire\Concerns\InPortal;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-18 tenant portal §4: documents staff shared with the tenant (plan ruling 13). */
#[Layout('layouts::portal')]
class Documents extends Component
{
    use InPortal;

    public function render(): View
    {
        return view('livewire.portal.documents', [
            'documents' => Document::query()->sharedWithTenant($this->customerId())->latest('id')->get(),
        ])->title(__('Documents'));
    }
}
