<?php

namespace App\Livewire\Units;

use App\CustomFields\CustomFieldFilter;
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Units')]
class Index extends Component
{
    use WithActor, WithPagination;

    #[Url]
    public string $search = '';

    /** Spec 2026-10-21 custom fields §5 "More filters": field, op, value, from, to, choices. Mixed and not in the URL, so a tampered value is ignored, never a TypeError (plan ruling 6). */
    public mixed $more = [];

    #[Url]
    public ?int $buildingId = null;

    #[Url]
    public string $type = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $units = Unit::query()
            ->visibleTo($this->actor())
            ->withOccupancy()
            ->with(['building:id,code,name', 'unitType:code,name'])
            ->when($this->buildingId, fn ($q) => $q->where('building_id', $this->buildingId))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->search !== '', fn ($q) => $q->where('code', 'like', '%'.$this->search.'%'))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Unit, $this->more))
            ->orderBy('building_id')->orderBy('code')
            ->paginate(50);

        return view('livewire.units.index', [
            'units' => $units,
            'customFields' => CustomFields::active(CustomFieldEntity::Unit),
            'types' => UnitType::query()->orderBy('name')->get(['code', 'name']),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
}
