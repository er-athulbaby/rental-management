<?php

namespace App\Livewire\Owners;

use App\CustomFields\CustomFieldFilter;
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use App\Models\Owner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Owners')]
class Index extends Component
{
    use WithPagination;

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
        $owners = Owner::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name_en', 'like', '%'.$this->search.'%')
                ->orWhere('id_number', $this->search)))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Owner, $this->more))
            ->orderBy('name_en')
            ->paginate(25);

        return view('livewire.owners.index', ['owners' => $owners, 'customFields' => CustomFields::active(CustomFieldEntity::Owner)]);
    }
}
