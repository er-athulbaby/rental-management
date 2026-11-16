<?php

namespace App\Livewire\Units;

use App\CustomFields\CustomFieldFilter;
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Unit;
use App\Models\UnitType;
use App\Reports\Queries;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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

    /** @return Builder<Unit> the list's filters: visibility, building, type, code search and custom fields */
    private function filtered(): Builder
    {
        return Unit::query()
            ->visibleTo($this->actor())
            ->when($this->buildingId, fn ($q) => $q->where('building_id', $this->buildingId))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->search !== '', fn ($q) => $q->where('code', 'like', '%'.$this->search.'%'))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Unit, $this->more));
    }

    /**
     * Design pass §5 (plan ruling 2): occupied today (Queries::occupiedUnitIds, as the dashboard's occupancy counts it),
     * blocked and not occupied, and vacant (neither; reserved units included). One aggregate query: filtered() left-joined
     * to today's occupied unit ids.
     *
     * @return array{occupied: int, vacant: int, blocked: int}
     */
    private function stats(): array
    {
        $row = (array) ($this->filtered()->toBase()
            ->leftJoinSub(Queries::occupiedUnitIds($this->actor(), now('Asia/Bahrain')->toDateString()), 'occ', 'occ.unit_id', '=', 'units.id')
            ->selectRaw('COUNT(*) AS unit_count, COUNT(occ.unit_id) AS occupied_count, SUM(CASE WHEN occ.unit_id IS NULL AND units.blocked = 1 THEN 1 ELSE 0 END) AS blocked_count')
            ->first() ?? []);
        $occupied = (int) ($row['occupied_count'] ?? 0);
        $blocked = (int) ($row['blocked_count'] ?? 0);

        return ['occupied' => $occupied, 'vacant' => (int) ($row['unit_count'] ?? 0) - $occupied - $blocked, 'blocked' => $blocked];
    }

    public function render(): View
    {
        $units = $this->filtered()
            ->withOccupancy()
            ->with(['building:id,code,name', 'unitType:code,name'])
            ->orderBy('building_id')->orderBy('code')
            ->paginate(50);

        return view('livewire.units.index', [
            'units' => $units,
            'stats' => $this->stats(),
            'customFields' => CustomFields::active(CustomFieldEntity::Unit),
            'types' => UnitType::query()->orderBy('name')->get(['code', 'name']),
            'buildings' => Building::query()->visibleTo($this->actor())->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }
}
