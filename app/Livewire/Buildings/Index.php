<?php

namespace App\Livewire\Buildings;

use App\CustomFields\CustomFieldFilter;
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Buildings')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $search = '';

    /** Spec 2026-10-21 custom fields §5 "More filters": field, op, value, from, to, choices. Mixed and not in the URL, so a tampered value is ignored, never a TypeError (plan ruling 6). */
    public mixed $more = [];

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $buildings = Building::query()
            ->visibleTo($this->actor())
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('code', 'like', '%'.$this->search.'%')))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Building, $this->more))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.buildings.index', ['buildings' => $buildings, 'customFields' => CustomFields::active(CustomFieldEntity::Building)]);
    }
}
