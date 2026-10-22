<?php

namespace App\Livewire\Portal\Owner;

use App\CustomFields\CustomFields;
use App\Livewire\Concerns\InPortal;
use App\Portal\OwnerPortal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Spec 2026-10-21 owner portal §4: one card per building of their contracts; the rent roll only while managed (plan ruling 9). */
#[Layout('layouts::portal')]
class Buildings extends Component
{
    use InPortal;

    public function render(): View
    {
        $buildings = OwnerPortal::buildings($this->ownerId());

        return view('livewire.portal.owner.buildings', [
            'buildings' => $buildings,
            // Spec 2026-10-21 custom fields §5: building fields marked "Show in portal" on the building cards (plan ruling 8).
            'details' => $buildings->mapWithKeys(fn (array $card) => [$card['building']->id => CustomFields::display($card['building'], portal: true)])->all(),
        ])->title(__('Buildings'));
    }
}
